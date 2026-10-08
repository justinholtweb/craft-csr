<?php

declare(strict_types=1);

namespace justinholtweb\csr\services;

use Craft;
use craft\base\Component;
use craft\db\Query;
use craft\events\ConfigEvent;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use DateInterval;
use DateTime;
use DateTimeZone;
use justinholtweb\csr\elements\Ticket;
use justinholtweb\csr\enums\EventType;
use justinholtweb\csr\models\Edition;
use justinholtweb\csr\models\SlaPolicy;
use justinholtweb\csr\Plugin;
use justinholtweb\csr\records\SlaPolicyRecord;
use justinholtweb\csr\records\Table;

/**
 * Promises with a clock on them.
 *
 * Three separate jobs, and it is worth being clear which is which:
 *
 * 1. **Matching.** Which policy claims a ticket, decided once when it is raised and again if the
 *    thing it was matched on changes.
 * 2. **Arithmetic.** Turning "four hours" into a moment in time, which is only hard because of
 *    opening hours — a four-hour target that starts at half past four on a Friday is not a promise
 *    anybody made, it is a breach scheduled for Saturday morning.
 * 3. **The sweep.** Noticing that a deadline passed and doing something about it.
 *
 * The clock **pauses** while a ticket is waiting on the customer, rather than stopping or running.
 * Running it means every policy is breached by people who reply slowly to their own support
 * requests, and the SLA report becomes a measurement of customer responsiveness. Stopping it
 * means a ticket can be parked in "pending" forever with a clean record.
 */
class Slas extends Component
{
    public const CONFIG_KEY = 'csr.slaPolicies';

    /** Refuse to walk more than a year forward looking for opening hours. */
    private const MAX_DAYS = 366;

    /** @var SlaPolicy[]|null */
    private ?array $_policies = null;

    private ?DateTimeZone $_timezone = null;

    // Reading
    // -------------------------------------------------------------------------

    /** @return SlaPolicy[] In match order — the first one that claims a ticket wins. */
    public function getAllPolicies(): array
    {
        if ($this->_policies !== null) {
            return $this->_policies;
        }

        $this->_policies = [];

        if (!Edition::allowsSlas(Plugin::getInstance()->isPro())) {
            // A lapsed licence stops the clock rather than breaching everything: existing due
            // dates stay on their tickets as a record of what was promised, and nothing new is
            // matched, swept or escalated.
            return $this->_policies;
        }

        /** @var SlaPolicyRecord[] $records */
        $records = SlaPolicyRecord::find()
            ->orderBy(['sortOrder' => SORT_ASC, 'name' => SORT_ASC])
            ->all();

        foreach ($records as $record) {
            $this->_policies[] = new SlaPolicy([
                'id' => (int)$record->id,
                'name' => $record->name,
                'handle' => $record->handle,
                'description' => $record->description,
                'enabled' => (bool)$record->enabled,
                'conditions' => $record->conditions,
                'firstResponseMinutes' => $record->firstResponseMinutes !== null ? (int)$record->firstResponseMinutes : null,
                'resolutionMinutes' => $record->resolutionMinutes !== null ? (int)$record->resolutionMinutes : null,
                'businessHoursOnly' => (bool)$record->businessHoursOnly,
                'escalateAfterMinutes' => $record->escalateAfterMinutes !== null ? (int)$record->escalateAfterMinutes : null,
                'escalateToTeamUid' => $record->escalateToTeamUid,
                'notifyOnBreach' => (bool)$record->notifyOnBreach,
                'sortOrder' => $record->sortOrder !== null ? (int)$record->sortOrder : null,
                'uid' => $record->uid,
            ]);
        }

        return $this->_policies;
    }

    public function getPolicyById(?int $id): ?SlaPolicy
    {
        if ($id === null) {
            return null;
        }

        foreach ($this->getAllPolicies() as $policy) {
            if ($policy->id === $id) {
                return $policy;
            }
        }

        return null;
    }

    public function getPolicyByHandle(string $handle): ?SlaPolicy
    {
        foreach ($this->getAllPolicies() as $policy) {
            if ($policy->handle === $handle) {
                return $policy;
            }
        }

        return null;
    }

    public function getPolicyByUid(string $uid): ?SlaPolicy
    {
        foreach ($this->getAllPolicies() as $policy) {
            if ($policy->uid === $uid) {
                return $policy;
            }
        }

        return null;
    }

    // Matching and applying
    // -------------------------------------------------------------------------

    /** The first enabled policy that claims this ticket, exactly like a firewall rule. */
    public function match(Ticket $ticket): ?SlaPolicy
    {
        foreach ($this->getAllPolicies() as $policy) {
            if ($policy->matches($ticket)) {
                return $policy;
            }
        }

        return null;
    }

    /**
     * Work out and stamp the due times, without saving.
     *
     * The caller saves, because this is normally called from inside a save that is happening
     * anyway — and because an SLA recalculation that writes on its own would produce a second
     * `dateUpdated` bump for every ticket in the sweep.
     */
    public function apply(Ticket $ticket, bool $recordEvent = true): ?SlaPolicy
    {
        if (!Plugin::getInstance()->getSettings()->slaEnabled) {
            return null;
        }

        $policy = $this->match($ticket);

        if ($policy === null) {
            return null;
        }

        $from = $ticket->submittedAt ?? new DateTime();

        $ticket->slaPolicyId = (int)$policy->id;
        $ticket->firstResponseDue = $policy->firstResponseMinutes !== null
            ? $this->due($from, $policy->firstResponseMinutes, $policy->businessHoursOnly)
            : null;
        $ticket->resolutionDue = $policy->resolutionMinutes !== null
            ? $this->due($from, $policy->resolutionMinutes, $policy->businessHoursOnly)
            : null;

        if ($recordEvent && $ticket->id !== null) {
            Plugin::getInstance()->tickets->recordEvent($ticket, EventType::SlaApplied, [
                'policy' => $policy->name,
                'firstResponseDue' => $ticket->firstResponseDue?->format(DATE_ATOM),
                'resolutionDue' => $ticket->resolutionDue?->format(DATE_ATOM),
            ]);
        }

        return $policy;
    }

    /**
     * Stop the clock, because the ticket is now waiting on the customer.
     *
     * Records *when* rather than adjusting the due dates now: the adjustment needs to know how
     * long the wait actually was, and nobody knows that until it ends.
     */
    public function pause(Ticket $ticket): void
    {
        if ($ticket->clockPausedAt === null && $ticket->getHasSla()) {
            $ticket->clockPausedAt = new DateTime();
        }
    }

    /**
     * Start it again, and give back the time that was spent waiting.
     *
     * The due dates move forward by the *business* time that elapsed while paused, which is what
     * "the clock was stopped" means. Wall-clock time would hand a desk an extra weekend for every
     * customer who took a weekend to reply.
     */
    public function resume(Ticket $ticket): void
    {
        if ($ticket->clockPausedAt === null) {
            return;
        }

        $policy = $ticket->getSlaPolicy();
        $businessOnly = $policy->businessHoursOnly ?? true;
        $now = new DateTime();

        $minutes = $this->minutesBetween($ticket->clockPausedAt, $now, $businessOnly);

        if ($minutes > 0) {
            if ($ticket->firstResponseDue !== null && $ticket->firstRespondedAt === null) {
                $ticket->firstResponseDue = $this->due($ticket->firstResponseDue, $minutes, $businessOnly);
            }

            if ($ticket->resolutionDue !== null && $ticket->resolvedAt === null) {
                $ticket->resolutionDue = $this->due($ticket->resolutionDue, $minutes, $businessOnly);
            }
        }

        $ticket->pausedSeconds += max(0, $now->getTimestamp() - $ticket->clockPausedAt->getTimestamp());
        $ticket->clockPausedAt = null;
    }

    // The arithmetic
    // -------------------------------------------------------------------------

    /**
     * `$minutes` after `$from`, counting only the hours the desk is open.
     *
     * Falls back to plain wall-clock arithmetic when opening hours are not in use, when the
     * edition does not include them, or when the desk is configured as never open — the last
     * because a deadline an hour from now is a worse answer than no deadline at all, but an
     * infinite loop is worse than both.
     */
    public function due(DateTime $from, int $minutes, bool $businessHoursOnly = true): DateTime
    {
        $settings = Plugin::getInstance()->getSettings();
        $usesHours = $businessHoursOnly
            && Edition::allowsBusinessHours(Plugin::getInstance()->isPro());

        if (!$usesHours || $minutes <= 0) {
            return (clone $from)->add(new DateInterval('PT' . max(0, $minutes) . 'M'));
        }

        $hours = $settings->getEffectiveBusinessHours();
        $holidays = array_flip($settings->getHolidays());
        $timezone = $this->getTimezone();

        $cursor = (clone $from)->setTimezone($timezone);
        $remaining = $minutes;

        for ($day = 0; $day < self::MAX_DAYS; $day++) {
            $window = $this->windowFor($cursor, $hours, $holidays);

            if ($window !== null) {
                [$open, $close] = $window;
                $start = $cursor < $open ? $open : $cursor;

                if ($start < $close) {
                    $available = (int)floor(($close->getTimestamp() - $start->getTimestamp()) / 60);

                    if ($available >= $remaining) {
                        return (clone $start)
                            ->add(new DateInterval('PT' . $remaining . 'M'))
                            ->setTimezone($from->getTimezone());
                    }

                    $remaining -= $available;
                }
            }

            $cursor = (clone $cursor)->modify('+1 day')->setTime(0, 0);
        }

        // The desk is never open, or the target is longer than a year of opening hours. Falling
        // back to wall-clock keeps the promise finite and visible rather than silently absent.
        return (clone $from)->add(new DateInterval('PT' . $minutes . 'M'));
    }

    /** How many countable minutes lie between two moments. */
    public function minutesBetween(DateTime $from, DateTime $to, bool $businessHoursOnly = true): int
    {
        if ($to <= $from) {
            return 0;
        }

        $settings = Plugin::getInstance()->getSettings();
        $usesHours = $businessHoursOnly
            && Edition::allowsBusinessHours(Plugin::getInstance()->isPro());

        if (!$usesHours) {
            return (int)floor(($to->getTimestamp() - $from->getTimestamp()) / 60);
        }

        $hours = $settings->getEffectiveBusinessHours();
        $holidays = array_flip($settings->getHolidays());
        $timezone = $this->getTimezone();

        $cursor = (clone $from)->setTimezone($timezone);
        $end = (clone $to)->setTimezone($timezone);
        $total = 0;

        for ($day = 0; $day < self::MAX_DAYS && $cursor < $end; $day++) {
            $window = $this->windowFor($cursor, $hours, $holidays);

            if ($window !== null) {
                [$open, $close] = $window;
                $start = $cursor < $open ? $open : $cursor;
                $finish = $end < $close ? $end : $close;

                if ($start < $finish) {
                    $total += (int)floor(($finish->getTimestamp() - $start->getTimestamp()) / 60);
                }
            }

            $cursor = (clone $cursor)->modify('+1 day')->setTime(0, 0);
        }

        return $total;
    }

    /**
     * The open window on the day `$cursor` falls in, or null if the desk is shut.
     *
     * @param array<string, array{open: string, close: string}> $hours
     * @param array<string, int> $holidays
     * @return array{0: DateTime, 1: DateTime}|null
     */
    private function windowFor(DateTime $cursor, array $hours, array $holidays): ?array
    {
        if (isset($holidays[$cursor->format('Y-m-d')])) {
            return null;
        }

        $weekday = strtolower($cursor->format('l'));
        $window = $hours[$weekday] ?? null;

        if ($window === null) {
            return null;
        }

        $open = (clone $cursor)->modify($window['open']);
        $close = (clone $cursor)->modify($window['close']);

        // A close time at or before the open time is a typo, not a night shift. Treating it as
        // "open until tomorrow" would hand out deadlines in the middle of the night.
        return $close > $open ? [$open, $close] : null;
    }

    private function getTimezone(): DateTimeZone
    {
        if ($this->_timezone !== null) {
            return $this->_timezone;
        }

        $configured = Plugin::getInstance()->getSettings()->businessTimezone;

        try {
            return $this->_timezone = new DateTimeZone($configured ?: Craft::$app->getTimeZone());
        } catch (\Throwable) {
            return $this->_timezone = new DateTimeZone(Craft::$app->getTimeZone());
        }
    }

    // The sweep
    // -------------------------------------------------------------------------

    /**
     * Find the tickets whose deadlines have passed, stamp them, and act.
     *
     * Idempotent by design: the stamped columns are what stop a ticket being reported twice, and
     * the query only looks at tickets that have not been stamped yet — so running the sweep every
     * five minutes and running it once an hour produce the same emails, not six times as many.
     *
     * @return array{firstResponse: int, resolution: int, escalated: int}
     */
    public function sweep(?int $limit = 500): array
    {
        $counts = ['firstResponse' => 0, 'resolution' => 0, 'escalated' => 0];

        if (!Plugin::getInstance()->getSettings()->slaEnabled || !Edition::allowsSlas(Plugin::getInstance()->isPro())) {
            return $counts;
        }

        $tickets = Plugin::getInstance()->tickets;
        $now = new DateTime();

        /** @var Ticket[] $candidates */
        $candidates = Ticket::find()
            ->openOnly()
            ->breaching()
            ->limit($limit)
            ->all();

        foreach ($candidates as $ticket) {
            $changed = false;

            if (
                !$ticket->firstResponseBreached
                && $ticket->firstResponseDue !== null
                && $ticket->firstRespondedAt === null
                && $ticket->firstResponseDue < $now
            ) {
                $ticket->firstResponseBreached = true;
                $changed = true;
                $counts['firstResponse']++;
                $tickets->recordEvent($ticket, EventType::SlaBreached, ['target' => 'firstResponse']);
                $this->notifyBreach($ticket, 'firstResponse');
            }

            if (
                !$ticket->resolutionBreached
                && $ticket->resolutionDue !== null
                && $ticket->resolvedAt === null
                && $ticket->resolutionDue < $now
            ) {
                $ticket->resolutionBreached = true;
                $changed = true;
                $counts['resolution']++;
                $tickets->recordEvent($ticket, EventType::SlaBreached, ['target' => 'resolution']);
                $this->notifyBreach($ticket, 'resolution');
            }

            if ($this->escalate($ticket, $now)) {
                $changed = true;
                $counts['escalated']++;
            }

            if ($changed) {
                Craft::$app->getElements()->saveElement($ticket, false, false, false);
            }
        }

        return $counts;
    }

    /**
     * Hand a late ticket to somebody else.
     *
     * Once only — `escalatedAt` is the guard. An escalation that fires every time the sweep runs
     * is an escalation everybody learns to ignore, which is worse than none.
     */
    private function escalate(Ticket $ticket, DateTime $now): bool
    {
        if ($ticket->escalatedAt !== null || $ticket->firstResponseDue === null) {
            return false;
        }

        $policy = $ticket->getSlaPolicy();

        if ($policy?->escalateAfterMinutes === null) {
            return false;
        }

        $threshold = (clone $ticket->firstResponseDue)
            ->add(new DateInterval('PT' . $policy->escalateAfterMinutes . 'M'));

        if ($now < $threshold || $ticket->firstRespondedAt !== null) {
            return false;
        }

        $team = $policy->getEscalationTeam();
        $ticket->escalatedAt = $now;

        if ($team !== null) {
            $ticket->teamId = (int)$team->id;
            // Cleared on purpose: the point of escalating is that the person it was with has not
            // got to it, so leaving it assigned to them changes nothing.
            $ticket->agentId = null;
        }

        Plugin::getInstance()->tickets->recordEvent($ticket, EventType::SlaEscalated, [
            'team' => $team->name ?? Craft::t('csr', 'the queue'),
        ]);

        Plugin::getInstance()->notifications->sendEscalation($ticket);

        return true;
    }

    private function notifyBreach(Ticket $ticket, string $target): void
    {
        $policy = $ticket->getSlaPolicy();

        if (
            Plugin::getInstance()->getSettings()->notifyOnSlaBreach
            && ($policy === null || $policy->notifyOnBreach)
        ) {
            Plugin::getInstance()->notifications->sendBreach($ticket, $target);
        }
    }

    // Writing
    // -------------------------------------------------------------------------

    public function savePolicy(SlaPolicy $policy, bool $runValidation = true): bool
    {
        if (!Edition::allowsSlas(Plugin::getInstance()->isPro())) {
            $policy->addError('name', Craft::t('csr', 'Service level agreements are a Pro feature.'));

            return false;
        }

        if ($runValidation && !$policy->validate()) {
            return false;
        }

        $isNew = $policy->id === null;

        if ($isNew) {
            $policy->uid = StringHelper::UUID();
            $policy->sortOrder ??= (int)((new Query())->from([Table::SLA_POLICIES])->max('[[sortOrder]]') ?? 0) + 1;
        } elseif (!$policy->uid) {
            $policy->uid = Db::uidById(Table::SLA_POLICIES, $policy->id);
        }

        Craft::$app->getProjectConfig()->set(
            self::CONFIG_KEY . '.' . $policy->uid,
            $policy->getConfig(),
            "Save the “{$policy->handle}” SLA policy",
        );

        if ($isNew) {
            $policy->id = Db::idByUid(Table::SLA_POLICIES, $policy->uid);
        }

        $this->refresh();

        return true;
    }

    /** @param string[] $uids In match order. */
    public function reorderPolicies(array $uids): bool
    {
        $projectConfig = Craft::$app->getProjectConfig();

        foreach ($uids as $index => $uid) {
            if ($this->getPolicyByUid((string)$uid) !== null) {
                $projectConfig->set(self::CONFIG_KEY . '.' . $uid . '.sortOrder', $index + 1, 'Reorder SLA policies');
            }
        }

        $this->refresh();

        return true;
    }

    public function deletePolicyById(int $id): bool
    {
        $policy = $this->getPolicyById($id);

        if ($policy === null) {
            return false;
        }

        Craft::$app->getProjectConfig()->remove(
            self::CONFIG_KEY . '.' . $policy->uid,
            "Delete the “{$policy->handle}” SLA policy",
        );

        $this->removePolicyRecord((string)$policy->uid);
        $this->refresh();

        return true;
    }

    public function handleChangedPolicy(ConfigEvent $event): void
    {
        $uid = $event->tokenMatches[0];
        $data = $event->newValue;

        $record = SlaPolicyRecord::findOne(['uid' => $uid]) ?? new SlaPolicyRecord();
        $record->uid = $uid;
        $record->name = $data['name'];
        $record->handle = $data['handle'];
        $record->description = $data['description'] ?? null;
        $record->enabled = (bool)($data['enabled'] ?? true);
        $record->conditions = json_encode($data['conditions'] ?? [], JSON_THROW_ON_ERROR);
        $record->firstResponseMinutes = $data['firstResponseMinutes'] ?? null;
        $record->resolutionMinutes = $data['resolutionMinutes'] ?? null;
        $record->businessHoursOnly = (bool)($data['businessHoursOnly'] ?? true);
        $record->escalateAfterMinutes = $data['escalateAfterMinutes'] ?? null;
        $record->escalateToTeamUid = $data['escalateToTeamUid'] ?? null;
        $record->notifyOnBreach = (bool)($data['notifyOnBreach'] ?? true);
        $record->sortOrder = $data['sortOrder'] ?? null;
        $record->save(false);

        $this->refresh();
    }

    public function handleDeletedPolicy(ConfigEvent $event): void
    {
        $this->removePolicyRecord($event->tokenMatches[0]);
        $this->refresh();
    }

    private function removePolicyRecord(string $uid): void
    {
        SlaPolicyRecord::findOne(['uid' => $uid])?->delete();
    }

    public function refresh(): void
    {
        $this->_policies = null;
        $this->_timezone = null;
    }

    /** @return array<string, mixed> */
    public function rebuildProjectConfig(): array
    {
        $config = [];

        /** @var SlaPolicyRecord[] $records */
        $records = SlaPolicyRecord::find()->all();

        foreach ($records as $record) {
            $config[$record->uid] = [
                'name' => $record->name,
                'handle' => $record->handle,
                'description' => $record->description ?: null,
                'enabled' => (bool)$record->enabled,
                'conditions' => json_decode((string)$record->conditions, true) ?: [],
                'firstResponseMinutes' => $record->firstResponseMinutes,
                'resolutionMinutes' => $record->resolutionMinutes,
                'businessHoursOnly' => (bool)$record->businessHoursOnly,
                'escalateAfterMinutes' => $record->escalateAfterMinutes,
                'escalateToTeamUid' => $record->escalateToTeamUid ?: null,
                'notifyOnBreach' => (bool)$record->notifyOnBreach,
                'sortOrder' => (int)$record->sortOrder,
            ];
        }

        return $config;
    }

    /** Used by the settings screen to show what a target would mean starting right now. */
    public function preview(int $minutes, bool $businessHoursOnly = true): DateTime
    {
        return $this->due(new DateTime(), $minutes, $businessHoursOnly);
    }
}
