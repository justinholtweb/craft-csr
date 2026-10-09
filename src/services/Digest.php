<?php

declare(strict_types=1);

namespace justinholtweb\csr\services;

use Craft;
use craft\base\Component;
use craft\db\Migration;
use craft\db\Query;
use craft\helpers\DateTimeHelper;
use craft\helpers\Db;
use craft\helpers\UrlHelper;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use justinholtweb\csr\elements\Ticket;
use justinholtweb\csr\events\DigestEvent;
use justinholtweb\csr\helpers\Mailer;
use justinholtweb\csr\jobs\SendDigest;
use justinholtweb\csr\models\Edition;
use justinholtweb\csr\models\Settings;
use justinholtweb\csr\Plugin;
use justinholtweb\csr\records\Table;
use Throwable;
use yii\db\IntegrityException;

/**
 * The scheduled desk digest: once a day or once a week, email the desk what is open, what is new
 * since the last one, and which promises have been broken.
 *
 * Ported from Yarn's findings digest, the family's reference for scheduled email reports (see
 * "Scheduled digests" in Yarn's CLAUDE.md). Everything above the "What this plugin reports" line
 * is Yarn's, unchanged but for names — schedule, the durable marker, the claim that makes a send
 * happen once, the fallback trigger. Below it is CSR's: what to collect, how to key it, what to
 * put in the email.
 *
 * Three ways in, one way through:
 *
 * - `php craft csr/digest/send` from cron — the recommended route, every 15–60 minutes;
 * - the end of a web request, at most every five minutes, which queues {@see SendDigest};
 * - "Send a test digest now" in the control panel, which never touches the marker.
 *
 * All of the scheduled ones end in {@see run()}, and `run()` is idempotent: the first caller to
 * move the marker onto the current period sends, everybody after it finds the period taken.
 * Nothing here hangs off `Gc::EVENT_RUN` — garbage collection runs on a dice roll, and a digest
 * that arrives on a dice roll is not a schedule.
 */
class Digest extends Component
{
    /** @see DigestEvent Cancel it, or change the recipients, subject or variables. */
    public const EVENT_BEFORE_SEND = 'beforeSend';

    public const TABLE = Table::DIGESTS;

    /** One row per digest the plugin sends. CSR has one. */
    public const HANDLE = 'desk';

    public const RESULT_SENT = 'sent';
    public const RESULT_DISABLED = 'disabled';
    public const RESULT_NO_RECIPIENTS = 'noRecipients';
    public const RESULT_NOT_DUE = 'notDue';
    public const RESULT_ALREADY_SENT = 'alreadySent';
    public const RESULT_NOTHING_NEW = 'nothingNew';
    public const RESULT_CANCELLED = 'cancelled';
    public const RESULT_FAILED = 'failed';

    /** Most tickets listed in each section of one email. The rest are a count and a link. */
    public const MAX_ITEMS = 25;

    /** How often the web fallback looks at the schedule at all. */
    public const WEB_CHECK_SECONDS = 300;

    private const WEB_CHECK_KEY = 'csr:digest:checked';
    private const QUEUED_KEY = 'csr:digest:queued:';

    // ------------------------------------------------------------------------------- schedule

    /** Now, in the system time zone — the one the digest hour is set in. */
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone(Craft::$app->getTimeZone()));
    }

    /**
     * The period a moment falls in: `2026-10-09` for a daily digest, `2026-W41` (ISO week) for a
     * weekly one. One send per key, ever.
     */
    public function periodKey(DateTimeInterface $now): string
    {
        $now = $this->inSystemTime($now);

        return $this->settings()->digestFrequency === Settings::DIGEST_DAILY
            ? $now->format('Y-m-d')
            : $now->format('o-\WW');
    }

    /** When the digest for the period containing `$now` becomes due. */
    public function dueAt(DateTimeInterface $now): DateTimeImmutable
    {
        $settings = $this->settings();
        $now = $this->inSystemTime($now);

        if ($settings->digestFrequency === Settings::DIGEST_WEEKLY) {
            $now = $now->setISODate((int)$now->format('o'), (int)$now->format('W'), $settings->digestWeekday);
        }

        return $now->setTime($settings->digestHour, 0);
    }

    /** Whether a scheduled run at `$now` would send (or at least try to). */
    public function isDue(?DateTimeInterface $now = null): bool
    {
        $settings = $this->settings();
        $now = $this->inSystemTime($now ?? $this->now());

        return $settings->digestEnabled
            && $this->recipients() !== []
            && $now >= $this->dueAt($now)
            && $this->state()['period'] !== $this->periodKey($now);
    }

    /**
     * When the next scheduled digest will go — for the settings screen. A digest that is due now
     * and has not gone yet answers with now.
     */
    public function nextDueAt(?DateTimeInterface $now = null): ?DateTimeImmutable
    {
        $settings = $this->settings();

        if (!$settings->digestEnabled) {
            return null;
        }

        $now = $this->inSystemTime($now ?? $this->now());

        if ($this->state()['period'] !== $this->periodKey($now)) {
            $due = $this->dueAt($now);

            return $due > $now ? $due : $now;
        }

        $next = $now->modify($settings->digestFrequency === Settings::DIGEST_DAILY ? '+1 day' : '+1 week');

        return $this->dueAt($next);
    }

    // --------------------------------------------------------------------------- the triggers

    /**
     * The scheduled send. Idempotent: call it as often as you like, it sends once per period.
     *
     * @param bool $force Ignore the schedule and the "already sent" marker — `--force` on the
     *                    console command. Still records the send, so the next scheduled one
     *                    reports what is new since *this*.
     * @return string One of the `RESULT_*` constants.
     */
    public function run(?DateTimeInterface $now = null, bool $force = false): string
    {
        $settings = $this->settings();
        $now = $this->inSystemTime($now ?? $this->now());

        if (!$force && !$settings->digestEnabled) {
            return self::RESULT_DISABLED;
        }

        $recipients = $this->recipients();

        if ($recipients === []) {
            return self::RESULT_NO_RECIPIENTS;
        }

        if (!$force && $now < $this->dueAt($now)) {
            return self::RESULT_NOT_DUE;
        }

        $period = $this->periodKey($now);
        $state = $this->state();

        if (!$force && ($state['period'] === $period || !$this->claim($period, $state['period']))) {
            return self::RESULT_ALREADY_SENT;
        }

        try {
            $current = $this->collect();
        } catch (Throwable $e) {
            $this->release($period, $state['period']);
            Craft::error('Could not assemble the desk digest: ' . $e->getMessage(), Plugin::LOG_CATEGORY);

            return $this->record(null, null, self::RESULT_FAILED);
        }

        $new = array_diff_key($current, array_flip($state['seen']));

        if ($new === [] && !$settings->digestSendWhenEmpty) {
            return $this->record($period, $current, self::RESULT_NOTHING_NEW);
        }

        $delivered = $this->deliver($recipients, $current, $new, false);

        if ($delivered === null) {
            $this->release($period, $state['period']);

            return $this->record(null, null, self::RESULT_CANCELLED);
        }

        if ($delivered === 0) {
            // Give the period back, so the next run tries again rather than the week going by
            // without a digest because the mail server was down for five minutes on Monday.
            $this->release($period, $state['period']);

            return $this->record(null, null, self::RESULT_FAILED);
        }

        return $this->record($period, $current, self::RESULT_SENT, count($new));
    }

    /**
     * "Send a test digest now". Sends what the next digest would say, marked as a test, and
     * leaves the marker alone — a test must never stop the real one going out.
     *
     * @param string[] $recipients
     * @return int How many recipients it was delivered to.
     */
    public function sendTest(array $recipients): int
    {
        $current = $this->collect();
        $new = array_diff_key($current, array_flip($this->state()['seen']));

        return (int)$this->deliver($recipients, $current, $new, true);
    }

    /**
     * The fallback for sites with no cron job: called at the end of web requests, looks at the
     * schedule at most every {@see WEB_CHECK_SECONDS}, and queues {@see \justinholtweb\csr\jobs\SendDigest} when due.
     *
     * Cheap on purpose — one cache read on almost every request, one row read every five
     * minutes. The job does the work; `run()` decides, so a duplicate job is a no-op.
     */
    public function queueIfDue(?DateTimeInterface $now = null): bool
    {
        $settings = $this->settings();

        if (!$settings->digestEnabled || !$settings->digestWebTrigger) {
            return false;
        }

        $cache = Craft::$app->getCache();

        // `add()` only writes when the key is absent, so of several requests landing together
        // one goes on to look at the schedule.
        if (!$cache->add(self::WEB_CHECK_KEY, 1, self::WEB_CHECK_SECONDS)) {
            return false;
        }

        $now = $this->inSystemTime($now ?? $this->now());

        if (!$this->isDue($now)) {
            return false;
        }

        if (!$cache->add(self::QUEUED_KEY . $this->periodKey($now), 1, 3600)) {
            return false;
        }

        Craft::$app->getQueue()->push(new SendDigest());

        return true;
    }

    // ------------------------------------------------------------------------ durable marker

    /**
     * The marker: which period was last claimed, what was in the last digest, and how it went.
     *
     * @return array{period: string|null, seen: string[], lastRunAt: \DateTime|null, lastSentAt: \DateTime|null, lastResult: string|null, lastCount: int}
     */
    public function state(): array
    {
        $row = $this->row();

        if ($row === null) {
            try {
                Db::insert(self::TABLE, ['handle' => self::HANDLE, 'lastCount' => 0]);
            } catch (IntegrityException) {
                // Somebody else made it between our read and our write. Theirs will do.
            }

            $row = $this->row() ?? [];
        }

        $seen = json_decode((string)($row['seen'] ?? ''), true);

        return [
            'period' => isset($row['period']) && $row['period'] !== '' ? (string)$row['period'] : null,
            'seen' => is_array($seen) ? array_values(array_filter($seen, 'is_string')) : [],
            'lastRunAt' => DateTimeHelper::toDateTime($row['lastRunAt'] ?? null) ?: null,
            'lastSentAt' => DateTimeHelper::toDateTime($row['lastSentAt'] ?? null) ?: null,
            'lastResult' => $row['lastResult'] ?? null,
            'lastCount' => (int)($row['lastCount'] ?? 0),
        ];
    }

    /**
     * Moves the marker onto `$period`, but only if it still says `$previous`. Of two runs racing
     * for the same period, the database lets exactly one of them through.
     */
    private function claim(string $period, ?string $previous): bool
    {
        return Db::update(
            self::TABLE,
            ['period' => $period],
            ['handle' => self::HANDLE, 'period' => $previous],
        ) === 1;
    }

    /** Hands a claimed period back after a failure, so the next run tries again. */
    private function release(string $period, ?string $previous): void
    {
        Db::update(self::TABLE, ['period' => $previous], ['handle' => self::HANDLE, 'period' => $period]);
    }

    /**
     * Writes down how a run went. `$period` and `$current` are null when nothing should change
     * but the result — a failure leaves the period and the "seen" list as they were.
     *
     * @param array<string, mixed>|null $current
     */
    private function record(?string $period, ?array $current, string $result, ?int $count = null): string
    {
        $now = new DateTimeImmutable();
        $columns = [
            'lastRunAt' => Db::prepareDateForDb($now),
            'lastResult' => $result,
        ];

        if ($period !== null) {
            $columns['period'] = $period;
        }

        if ($current !== null) {
            $columns['seen'] = json_encode(array_keys($current));
        }

        if ($result === self::RESULT_SENT) {
            $columns['lastSentAt'] = Db::prepareDateForDb($now);
            $columns['lastCount'] = (int)$count;
        }

        Db::update(self::TABLE, $columns, ['handle' => self::HANDLE]);

        if ($result === self::RESULT_FAILED) {
            Craft::warning('The desk digest was not sent; the next run will try again.', Plugin::LOG_CATEGORY);
        } else {
            Craft::info("Desk digest: $result.", Plugin::LOG_CATEGORY);
        }

        return $result;
    }

    /** @return array<string, mixed>|null */
    private function row(): ?array
    {
        return (new Query())->from([self::TABLE])->where(['handle' => self::HANDLE])->one() ?: null;
    }

    /** Shared by `Install` and the migration that adds the table to an existing install. */
    public static function createTable(Migration $migration): void
    {
        $migration->createTable(self::TABLE, [
            'id' => $migration->primaryKey(),
            'handle' => $migration->string(64)->notNull(),
            // The period last claimed: `2026-10-09` or `2026-W41`. Null until the first run.
            'period' => $migration->string(32),
            // JSON list of the keys reported last time, so the next digest can say what is new.
            'seen' => $migration->mediumText(),
            'lastRunAt' => $migration->dateTime(),
            'lastSentAt' => $migration->dateTime(),
            'lastResult' => $migration->string(32),
            'lastCount' => $migration->integer()->notNull()->defaultValue(0),
            'dateCreated' => $migration->dateTime()->notNull(),
            'dateUpdated' => $migration->dateTime()->notNull(),
            'uid' => $migration->uid(),
        ]);

        $migration->createIndex(null, self::TABLE, ['handle'], true);
    }

    // ---------------------------------------------------------------------------- the email

    /**
     * Renders and sends. Null when an event handler cancelled it.
     *
     * @param string[] $recipients
     * @param array<string, array{ticket: Ticket, kind: string}> $current
     * @param array<string, array{ticket: Ticket, kind: string}> $new
     */
    private function deliver(array $recipients, array $current, array $new, bool $test): ?int
    {
        $variables = $this->variables($current, $new, $test);
        $subject = $this->subject($current, $new, $test);

        $event = new DigestEvent([
            'recipients' => $recipients,
            'subject' => $subject,
            'variables' => $variables,
            'isTest' => $test,
        ]);
        $this->trigger(self::EVENT_BEFORE_SEND, $event);

        if (!$event->isValid) {
            return null;
        }

        return Mailer::send($event->recipients, $event->subject, 'csr/_emails/digest', $event->variables);
    }

    // ===================================================================== What this plugin reports
    //
    // Everything below is CSR's: the desk's open tickets and its broken promises.

    /**
     * Who gets it: the digest's own list, plus — when the setting says so — everybody who can
     * answer tickets. Agents are the people a desk digest is *for*; making an admin type every
     * one of their addresses into a box, and keep it up to date, is how a digest ends up going to
     * three people who left.
     *
     * @return list<string>
     */
    public function recipients(): array
    {
        $settings = $this->settings();
        $recipients = $settings->recipientList();

        if ($settings->digestIncludeAgents) {
            foreach (Plugin::getInstance()->tickets->getAssignableAgents() as $agentId) {
                $email = Craft::$app->getUsers()->getUserById($agentId)?->email;

                if ($email) {
                    $recipients[] = $email;
                }
            }
        }

        return array_values(array_unique(array_map('strtolower', $recipients)));
    }

    /**
     * Every open ticket, and — where SLAs are in play — every one past its target, keyed by id
     * and kind. A ticket that goes late after the last digest is new news even though the ticket
     * itself is not, which is why "late" is a key of its own.
     *
     * @return array<string, array{ticket: Ticket, kind: string}>
     */
    public function collect(): array
    {
        $plugin = Plugin::getInstance();
        $slas = Edition::allowsSlas($plugin->isPro()) && $plugin->getSettings()->slaEnabled;
        $keyed = [];

        /** @var Ticket[] $tickets */
        $tickets = Ticket::find()->openOnly()->isSpam(false)->orderByQueue()->limit(1000)->all();

        foreach ($tickets as $ticket) {
            $keyed[$this->key($ticket, 'open')] = ['ticket' => $ticket, 'kind' => 'open'];

            if ($slas && $ticket->getIsBreaching()) {
                $keyed[$this->key($ticket, 'late')] = ['ticket' => $ticket, 'kind' => 'late'];
            }
        }

        return $keyed;
    }

    /** Ids, never titles or numbers that could be reused. */
    public function key(Ticket $ticket, string $kind): string
    {
        return $kind . ':' . $ticket->id;
    }

    /**
     * @param array<string, array{ticket: Ticket, kind: string}> $current
     * @param array<string, array{ticket: Ticket, kind: string}> $new
     */
    private function subject(array $current, array $new, bool $test): string
    {
        $open = count(array_filter($current, static fn(array $item) => $item['kind'] === 'open'));
        $late = count(array_filter($current, static fn(array $item) => $item['kind'] === 'late'));

        $subject = $late > 0
            ? Craft::t('csr', '{open, plural, =1{1 open ticket} other{# open tickets}}, {late} late', ['open' => $open, 'late' => $late])
            : Craft::t('csr', '{open, plural, =0{No open tickets} =1{1 open ticket} other{# open tickets}}', ['open' => $open]);

        return ($test ? Craft::t('csr', '[Test]') . ' ' : '') . Craft::t('csr', 'Support desk') . ': ' . $subject;
    }

    /**
     * @param array<string, array{ticket: Ticket, kind: string}> $current
     * @param array<string, array{ticket: Ticket, kind: string}> $new
     * @return array<string, mixed>
     */
    private function variables(array $current, array $new, bool $test): array
    {
        $plugin = Plugin::getInstance();
        $row = static fn(Ticket $ticket): array => [
            'number' => $ticket->number,
            'title' => (string)$ticket->title,
            'requester' => $ticket->getRequesterLabel(),
            'priority' => $ticket->getPriorityLabel(),
            'status' => (string)$ticket->getTicketStatus()?->name,
            'agent' => (string)($ticket->getAgent()?->getFriendlyName() ?? Craft::t('csr', 'Unassigned')),
            'submittedAt' => $ticket->submittedAt,
            'url' => UrlHelper::cpUrl('csr/tickets/' . $ticket->id),
        ];

        $newOpen = [];
        $newLate = [];
        $late = [];

        foreach ($new as $item) {
            if ($item['kind'] === 'open') {
                $newOpen[] = $row($item['ticket']);
            } else {
                $newLate[] = $row($item['ticket']);
            }
        }

        foreach ($current as $item) {
            if ($item['kind'] === 'late') {
                $late[] = $row($item['ticket']);
            }
        }

        $state = $this->state();

        return [
            'isTest' => $test,
            'since' => $state['lastSentAt'],
            'counts' => $plugin->tickets->getQueueCounts(),
            'slasOn' => Edition::allowsSlas($plugin->isPro()) && $plugin->getSettings()->slaEnabled,
            'newOpen' => array_slice($newOpen, 0, self::MAX_ITEMS),
            'newOpenCount' => count($newOpen),
            'newLate' => array_slice($newLate, 0, self::MAX_ITEMS),
            'newLateCount' => count($newLate),
            'stillLateCount' => max(0, count($late) - count($newLate)),
            'max' => self::MAX_ITEMS,
            'deskName' => Craft::$app->getSites()->getPrimarySite()->getName(),
            'ticketsUrl' => UrlHelper::cpUrl('csr/tickets'),
            'settingsUrl' => UrlHelper::cpUrl('csr/settings/notifications'),
        ];
    }

    // ---------------------------------------------------------------------------------- misc

    private function inSystemTime(DateTimeInterface $moment): DateTimeImmutable
    {
        return DateTimeImmutable::createFromInterface($moment)
            ->setTimezone(new DateTimeZone(Craft::$app->getTimeZone()));
    }

    private function settings(): Settings
    {
        return Plugin::getInstance()->getSettings();
    }
}
