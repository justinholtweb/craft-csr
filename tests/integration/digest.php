<?php
/**
 * The desk digest: settings, schedule, the durable marker, the email, the queue fallback and the
 * console command. Ported with Yarn's digest (the family reference), so the generic half is tested
 * the same way.
 *
 *     docker exec -w /var/www/html ddev-plugin-testing-web php /var/www/craft-csr/tests/integration/digest.php
 *
 * Self-cleaning. Settings are swapped in memory; the marker row is captured and put back; mail is
 * captured from the mailer's own event and never sent. The harness holds other suites' open
 * tickets, so assertions look for *this* run's tickets in the digest rather than counting.
 */

$root = '/var/www/html';
require $root . '/bootstrap.php';

/** @var craft\console\Application $app */
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';

use craft\db\Query;
use craft\elements\User;
use craft\helpers\Db;
use craft\mail\Mailer as CraftMailer;
use justinholtweb\csr\elements\Ticket;
use justinholtweb\csr\enums\StatusKind;
use justinholtweb\csr\enums\TicketSource;
use justinholtweb\csr\events\DigestEvent;
use justinholtweb\csr\jobs\SendDigest;
use justinholtweb\csr\models\Settings;
use justinholtweb\csr\Plugin;
use justinholtweb\csr\services\Digest;
use yii\base\Event;
use yii\mail\BaseMailer;
use yii\mail\MailEvent;

$passed = 0;
$failed = 0;

function check(string $label, callable $test): void
{
    global $passed, $failed;

    try {
        $result = $test();
    } catch (Throwable $e) {
        $result = get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine();
    }

    if ($result === true) {
        $passed++;
        echo "  ✓ $label\n";
    } else {
        $failed++;
        echo "  ✗ $label\n    " . (is_string($result) ? $result : var_export($result, true)) . "\n";
    }
}

function section(string $title): void
{
    echo "\n$title\n";
}

$plugin = Plugin::getInstance();
$plugin->edition = Plugin::EDITION_PRO;
$digest = $plugin->digest;
$original = $plugin->getSettings()->toArray();
$originalRow = (new Query())->from([Digest::TABLE])->where(['handle' => Digest::HANDLE])->one() ?: null;
$tz = new DateTimeZone(Craft::$app->getTimeZone());
$queueFloor = (int)(new Query())->from('{{%queue}}')->max('id');

function configure(array $overrides = []): Settings
{
    global $original;

    $plugin = Plugin::getInstance();
    $plugin->setSettings(array_merge($original, [
        'notifyRequesterOnNew' => false,
        'notifyAgentsOnNew' => false,
        'queueNotifications' => false,
        'autoAssign' => 'none',
    ], $overrides));

    return $plugin->getSettings();
}

function at(string $when): DateTimeImmutable
{
    global $tz;

    return new DateTimeImmutable($when, $tz);
}

function resetMarker(): void
{
    Db::delete(Digest::TABLE, ['handle' => Digest::HANDLE]);
}

$sent = [];
$refuse = false;
Event::on(CraftMailer::class, BaseMailer::EVENT_BEFORE_SEND, function(MailEvent $event) use (&$sent, &$refuse) {
    if (!$refuse) {
        $sent[] = $event->message;
    }

    // Never actually sent, refused or not.
    $event->isValid = false;
});

// The mailer reports a refused message as not sent, which the digest would count as a failure. So
// "sent" here is decided by the capture, and Mailer::send() is told the truth through this flag.
$lastVariables = null;
Event::on(Digest::class, Digest::EVENT_BEFORE_SEND, function(DigestEvent $event) use (&$lastVariables) {
    $lastVariables = $event->variables;
});

function drain(): array
{
    global $sent;

    $out = $sent;
    $sent = [];

    return $out;
}

// Mailer::send() returns false when EVENT_BEFORE_SEND says no, which is how every message here
// ends. Swap in a mailer subclass whose send() reports what the capture decided.
$realMailer = Craft::$app->getMailer();
$fakeMailer = new class($realMailer) extends CraftMailer {
    public function __construct(private CraftMailer $inner)
    {
        parent::__construct(['from' => $inner->from, 'messageClass' => $inner->messageClass]);
    }

    public function send($message): bool
    {
        global $sent, $refuse;

        if ($refuse) {
            return false;
        }

        $sent[] = $message;

        return true;
    }
};
Craft::$app->set('mailer', $fakeMailer);

$admin = User::find()->admin(true)->status(null)->one();
$prefix = 'dgt';
$mine = [];

$makeTicket = static function(string $name, array $attributes = []) use ($plugin, $prefix, &$mine): Ticket {
    $ticket = $plugin->tickets->submit([
        'email' => $name . '@digest.example',
        'subject' => "$prefix $name",
        'message' => 'Digest check',
        'source' => TicketSource::Web,
        'requesterId' => null,
    ], false);

    foreach ($attributes as $key => $value) {
        $ticket->$key = $value;
    }

    Craft::$app->getElements()->saveElement($ticket, false);
    $mine[] = $ticket;

    return $ticket;
};

$numbersIn = static fn(?array $rows) => array_map(static fn(array $row) => $row['number'], $rows ?? []);

try {
    // ========================================================================== settings
    section('Settings');

    check('a bare install is valid — nothing about the digest is required', function() {
        $settings = new Settings();

        return $settings->validate() && $settings->digestEnabled === false ?: json_encode($settings->getErrors());
    });

    check('recipients split on commas, semicolons and new lines; a bad one fails by name', function() {
        $settings = new Settings();
        $settings->setAttributes(['digestRecipients' => "a@example.test, b@example.test;\nb@example.test"], false);
        $bad = new Settings();
        $bad->setAttributes(['digestRecipients' => 'ok@example.test, not-an-address'], false);
        $bad->validate();

        return $settings->recipientList() === ['a@example.test', 'b@example.test']
            && str_contains($bad->getErrors('digestRecipients')[0] ?? '', 'not-an-address')
            ?: json_encode([$settings->recipientList(), $bad->getErrors()]);
    });

    check('hour, weekday and frequency are range-checked; posted strings are cast', function() {
        $settings = new Settings();
        $settings->setAttributes(['digestHour' => '9', 'digestWeekday' => '3', 'digestEnabled' => '1'], false);
        $bad = new Settings();
        $bad->setAttributes(['digestHour' => '24', 'digestWeekday' => '0', 'digestFrequency' => 'hourly'], false);
        $bad->validate();

        return $settings->digestHour === 9 && $settings->digestWeekday === 3 && $settings->digestEnabled === true
            && $bad->hasErrors('digestHour') && $bad->hasErrors('digestWeekday') && $bad->hasErrors('digestFrequency')
            ?: json_encode($bad->getErrors());
    });

    check('“also send it to agents” adds everybody who can answer tickets', function() use ($digest, $admin) {
        configure(['digestRecipients' => 'ops@example.test', 'digestIncludeAgents' => true]);
        $with = $digest->recipients();
        configure(['digestRecipients' => 'ops@example.test', 'digestIncludeAgents' => false]);
        $without = $digest->recipients();

        return in_array(strtolower((string)$admin->email), $with, true) && $without === ['ops@example.test'] ?: json_encode([$with, $without]);
    });

    // ========================================================================== schedule
    section('Schedule');

    check('a daily period is the date; a weekly one is the ISO week', function() use ($digest) {
        configure(['digestFrequency' => 'daily']);
        $daily = $digest->periodKey(at('2026-10-11 23:30'));
        configure(['digestFrequency' => 'weekly']);
        $newYear = $digest->periodKey(at('2027-01-01 12:00'));

        return [$daily, $newYear] === ['2026-10-11', '2026-W53'] ?: json_encode([$daily, $newYear]);
    });

    check('switched off: nothing is sent and nothing is recorded', function() use ($digest) {
        resetMarker();
        configure(['digestEnabled' => false, 'digestRecipients' => 'ops@example.test']);
        $result = $digest->run(at('2026-10-12 10:00'));

        return $result === Digest::RESULT_DISABLED && drain() === [] ?: $result;
    });

    check('switched on with nobody to send to says so', function() use ($digest) {
        configure(['digestEnabled' => true, 'digestRecipients' => '', 'digestIncludeAgents' => false]);

        return $digest->run(at('2026-10-12 10:00')) === Digest::RESULT_NO_RECIPIENTS ?: 'sent anyway';
    });

    check('before the hour it is not due', function() use ($digest) {
        configure(['digestEnabled' => true, 'digestRecipients' => 'ops@example.test', 'digestIncludeAgents' => false, 'digestFrequency' => 'daily', 'digestHour' => 8]);

        return $digest->run(at('2026-10-12 07:59')) === Digest::RESULT_NOT_DUE && drain() === [] ?: 'went early';
    });

    // ========================================================================== runs
    section('Runs, against tickets made on purpose');

    $late = $makeTicket('late', [
        'statusId' => $plugin->statuses->getFirstOfKind(StatusKind::Open)->id,
        'firstResponseDue' => new DateTime('-3 hours'),
        'priority' => 'urgent',
    ]);
    $fresh = $makeTicket('fresh', ['firstResponseDue' => new DateTime('+5 hours')]);
    $daily = ['digestEnabled' => true, 'digestRecipients' => 'ops@example.test, lead@example.test', 'digestIncludeAgents' => false, 'digestFrequency' => 'daily', 'digestHour' => 8, 'slaEnabled' => true];

    check('the first due run sends to each recipient separately, HTML and text', function() use ($digest, $daily, $late) {
        resetMarker();
        configure($daily);
        $result = $digest->run(at('2026-10-12 08:05'));
        $mail = drain();
        $html = (string)($mail[0] ?? null)?->getSymfonyEmail()->getHtmlBody();
        $text = (string)($mail[0] ?? null)?->getSymfonyEmail()->getTextBody();

        return $result === Digest::RESULT_SENT && count($mail) === 2
            && str_contains($html, '#' . $late->number) && str_contains($text, '#' . $late->number)
            && str_contains((string)$mail[0]->getSubject(), 'late')
            ?: "$result, " . count($mail) . ' messages';
    });

    check('the late ticket is listed as gone past a target, and both tickets as new', function() use (&$lastVariables, $late, $fresh, $numbersIn) {
        return in_array($late->number, $numbersIn($lastVariables['newLate'] ?? null), true)
            && in_array($fresh->number, $numbersIn($lastVariables['newOpen'] ?? null), true)
            && !in_array($fresh->number, $numbersIn($lastVariables['newLate'] ?? null), true)
            ?: json_encode([$lastVariables['newLate'] ?? null]);
    });

    check('the same period again: already sent, nothing mailed', function() use ($digest) {
        return $digest->run(at('2026-10-12 15:00')) === Digest::RESULT_ALREADY_SENT && drain() === [] ?: 'sent twice';
    });

    check('the next day with nothing new: nothing mailed, but the period is recorded', function() use ($digest) {
        $result = $digest->run(at('2026-10-13 09:00'));

        return $result === Digest::RESULT_NOTHING_NEW && drain() === [] && $digest->state()['period'] === '2026-10-13' ?: $result;
    });

    check('a new ticket, and a ticket going late, are news the day after', function() use ($digest, $makeTicket, $fresh, &$lastVariables, $numbersIn) {
        $newer = $makeTicket('newer');
        $fresh->firstResponseDue = new DateTime('-1 minute');
        Craft::$app->getElements()->saveElement($fresh, false);

        $result = $digest->run(at('2026-10-14 08:30'));
        drain();

        return $result === Digest::RESULT_SENT
            && in_array($newer->number, $numbersIn($lastVariables['newOpen']), true)
            && in_array($fresh->number, $numbersIn($lastVariables['newLate']), true)
            && !in_array($fresh->number, $numbersIn($lastVariables['newOpen']), true)
            ?: "$result " . json_encode([$numbersIn($lastVariables['newOpen'] ?? null), $numbersIn($lastVariables['newLate'] ?? null)]);
    });

    check('with SLAs off (or on Lite) the late section is not there', function() use ($digest, $daily, &$lastVariables) {
        resetMarker();
        configure(array_merge($daily, ['slaEnabled' => false]));
        $digest->run(at('2026-10-15 09:00'));
        drain();

        return ($lastVariables['slasOn'] ?? true) === false && ($lastVariables['newLateCount'] ?? 1) === 0 ?: json_encode($lastVariables['slasOn'] ?? null);
    });

    // ========================================================================== failure and cancel
    section('Failure, cancel and test sends');

    check('a mail failure releases the period, so the next run tries again', function() use ($digest, $daily, &$refuse) {
        resetMarker();
        configure($daily);
        $refuse = true;

        try {
            $first = $digest->run(at('2026-10-16 09:00'));
        } finally {
            $refuse = false;
        }

        $second = $digest->run(at('2026-10-16 09:30'));
        drain();

        return $first === Digest::RESULT_FAILED && $second === Digest::RESULT_SENT ?: "$first then $second";
    });

    check('an event handler can cancel it; the period stays open', function() use ($digest, $daily) {
        resetMarker();
        configure($daily);
        $handler = static fn(DigestEvent $event) => $event->isValid = false;
        Event::on(Digest::class, Digest::EVENT_BEFORE_SEND, $handler);

        try {
            $result = $digest->run(at('2026-10-17 09:00'));
        } finally {
            Event::off(Digest::class, Digest::EVENT_BEFORE_SEND, $handler);
        }

        return $result === Digest::RESULT_CANCELLED && drain() === [] && $digest->state()['period'] === null ?: $result;
    });

    check('a test send is marked as a test and never moves the marker', function() use ($digest, $daily) {
        resetMarker();
        configure($daily);
        $before = $digest->state()['period'];
        $count = $digest->sendTest(['me@example.test']);
        $mail = drain();

        return $count === 1 && str_starts_with((string)$mail[0]->getSubject(), '[Test]')
            && str_contains((string)$mail[0]->getSymfonyEmail()->getHtmlBody(), 'This is a test')
            && $digest->state()['period'] === $before
            ?: "$count / " . ($mail[0] ?? null)?->getSubject();
    });

    // ========================================================================== triggers
    section('Triggers');

    check('the web fallback queues SendDigest once when due, and not when it is not', function() use ($digest, $daily, $queueFloor) {
        resetMarker();
        configure(array_merge($daily, ['digestHour' => 0, 'digestWebTrigger' => true]));
        $cache = Craft::$app->getCache();
        $cache->delete('csr:digest:checked');
        $cache->delete('csr:digest:queued:' . $digest->periodKey($digest->now()));

        $queued = $digest->queueIfDue();
        $again = $digest->queueIfDue();
        $jobs = (int)(new Query())->from('{{%queue}}')->where(['>', 'id', $queueFloor])->andWhere(['like', 'job', 'SendDigest'])->count();

        configure(array_merge($daily, ['digestEnabled' => false]));
        $cache->delete('csr:digest:checked');
        $off = $digest->queueIfDue();

        return $queued && !$again && $jobs === 1 && !$off ?: json_encode([$queued, $again, $jobs, $off]);
    });

    check('the queued job just calls run(), so running it twice sends once', function() use ($digest, $daily) {
        resetMarker();
        configure(array_merge($daily, ['digestHour' => 0]));
        (new SendDigest())->execute(Craft::$app->getQueue());
        $first = count(drain());
        (new SendDigest())->execute(Craft::$app->getQueue());
        $second = count(drain());

        return $first === 2 && $second === 0 ?: "$first then $second";
    });

    check('`csr/digest/send` with the digest off exits 0 and says why; `status` reports the schedule', function() {
        exec('cd /var/www/html && php craft csr/digest/send 2>&1', $out, $code);
        exec('cd /var/www/html && php craft csr/digest/status 2>&1', $status, $statusCode);
        $out = implode("\n", $out);

        // The harness's saved settings have the digest off; this process's in-memory settings do
        // not reach a separate command.
        return $code === 0 && str_contains($out, 'switched off') && $statusCode === 0 && str_contains(implode("\n", $status), 'Next due')
            ?: "exit $code: $out";
    });
} finally {
    Craft::$app->set('mailer', $realMailer);

    foreach ($mine as $ticket) {
        Craft::$app->getElements()->deleteElement($ticket, true);
    }

    foreach (Ticket::find()->isSpam(null)->limit(null)->all() as $stale) {
        if (str_starts_with((string)$stale->title, $prefix . ' ')) {
            Craft::$app->getElements()->deleteElement($stale, true);
        }
    }

    resetMarker();

    if ($originalRow !== null) {
        unset($originalRow['id']);
        Db::insert(Digest::TABLE, $originalRow);
    }

    Craft::$app->getDb()->createCommand()->delete('{{%queue}}', ['>', 'id', $queueFloor])->execute();
    $plugin->setSettings($original);
}

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
