<?php

declare(strict_types=1);

namespace justinholtweb\csr\models;

use Craft;
use craft\base\Model;
use craft\helpers\UrlHelper;
use craft\validators\HandleValidator;
use justinholtweb\csr\elements\Ticket;
use justinholtweb\csr\enums\Priority;
use justinholtweb\csr\Plugin;

/**
 * A promise with a clock on it.
 *
 * Two promises, in fact, and keeping them apart is what makes an SLA report worth reading:
 *
 * - **First response** — how long somebody waits before a human says anything at all. This is the
 *   number customers actually feel, and the one a desk can nearly always hit.
 * - **Resolution** — how long until the thing is fixed. Frequently outside the desk's control,
 *   which is why breaching it means something different and is reported separately.
 *
 * ## Matching
 *
 * A policy claims a ticket by matching *every* condition it states and ignoring the ones it does
 * not. An empty condition is not "match nothing", it is "do not care" — a policy that says only
 * `priorities: [urgent]` covers every urgent ticket regardless of category, which is what somebody
 * writing that policy meant. Policies are tried in order and the first match wins, so the specific
 * ones go at the top and the catch-all at the bottom, exactly like a firewall.
 *
 * ## Why the conditions hold uids
 *
 * Because the policy lives in project config and travels between environments, where a category's
 * integer ID is meaningless. Uids survive the trip; IDs do not.
 */
class SlaPolicy extends Model
{
    public ?int $id = null;
    public string $name = '';
    public string $handle = '';
    public ?string $description = null;
    public bool $enabled = true;

    /** Minutes to a first response. Null means this policy makes no such promise. */
    public ?int $firstResponseMinutes = null;

    /** Minutes to resolution. Null means this policy makes no such promise. */
    public ?int $resolutionMinutes = null;

    /**
     * Whether the clock only runs while the desk is open.
     *
     * On by default, because a four-hour SLA that starts at half past four on a Friday is not a
     * promise anybody made — it is a breach scheduled for Saturday morning.
     */
    public bool $businessHoursOnly = true;

    /** Minutes past the first-response due time before the ticket is handed on. */
    public ?int $escalateAfterMinutes = null;

    public ?string $escalateToTeamUid = null;

    public bool $notifyOnBreach = true;

    public ?int $sortOrder = null;
    public ?string $uid = null;

    /** @var array{priorities: string[], categoryUids: string[], teamUids: string[], sources: string[]} */
    private array $_conditions = [
        'priorities' => [],
        'categoryUids' => [],
        'teamUids' => [],
        'sources' => [],
    ];

    public function __toString(): string
    {
        return $this->name;
    }

    public function rules(): array
    {
        return [
            [['name', 'handle'], 'required'],
            [['name', 'handle'], 'string', 'max' => 255],
            [['handle'], HandleValidator::class],
            [['firstResponseMinutes', 'resolutionMinutes', 'escalateAfterMinutes'], 'integer', 'min' => 1],
            [['sortOrder'], 'integer'],
            // A policy that promises nothing is almost certainly a half-filled form, and it would
            // silently claim tickets away from the policy below it that does promise something.
            [['firstResponseMinutes'], 'validateMakesAPromise', 'skipOnEmpty' => false],
            // Resolution before first response is the wrong way round, and always a typo.
            [['resolutionMinutes'], 'validateOrder', 'skipOnEmpty' => false],
        ];
    }

    public function validateMakesAPromise(string $attribute): void
    {
        if ($this->firstResponseMinutes === null && $this->resolutionMinutes === null) {
            $this->addError($attribute, Craft::t('csr', 'Set a first response target, a resolution target, or both.'));
        }
    }

    public function validateOrder(string $attribute): void
    {
        if (
            $this->firstResponseMinutes !== null &&
            $this->resolutionMinutes !== null &&
            $this->resolutionMinutes < $this->firstResponseMinutes
        ) {
            $this->addError($attribute, Craft::t('csr', 'Resolution cannot be due before the first response.'));
        }
    }

    // Conditions
    // -------------------------------------------------------------------------

    /** @return array{priorities: string[], categoryUids: string[], teamUids: string[], sources: string[]} */
    public function getConditions(): array
    {
        return $this->_conditions;
    }

    public function setConditions(mixed $value): void
    {
        if (is_string($value)) {
            $value = json_decode($value, true);
        }

        $value = is_array($value) ? $value : [];

        foreach (array_keys($this->_conditions) as $key) {
            $values = $value[$key] ?? [];
            $this->_conditions[$key] = array_values(array_filter(array_map(
                'strval',
                is_array($values) ? $values : [$values],
            ), static fn(string $item) => $item !== ''));
        }
    }

    /**
     * Whether this policy claims the ticket.
     *
     * Every stated condition must match; unstated ones are not consulted. See the note about
     * "do not care" at the top of the class — this is the method it describes.
     */
    public function matches(Ticket $ticket): bool
    {
        if (!$this->enabled) {
            return false;
        }

        $conditions = $this->_conditions;

        if ($conditions['priorities'] !== [] && !in_array($ticket->priority, $conditions['priorities'], true)) {
            return false;
        }

        if ($conditions['sources'] !== [] && !in_array($ticket->source, $conditions['sources'], true)) {
            return false;
        }

        if ($conditions['categoryUids'] !== []) {
            $uid = $ticket->getCategory()?->uid;

            if ($uid === null || !in_array($uid, $conditions['categoryUids'], true)) {
                return false;
            }
        }

        if ($conditions['teamUids'] !== []) {
            $uid = $ticket->getTeam()?->uid;

            if ($uid === null || !in_array($uid, $conditions['teamUids'], true)) {
                return false;
            }
        }

        return true;
    }

    public function getEscalationTeam(): ?Team
    {
        return $this->escalateToTeamUid !== null
            ? Plugin::getInstance()->teams->getTeamByUid($this->escalateToTeamUid)
            : null;
    }

    /** A human sentence for the policy list, so nobody has to open it to see what it does. */
    public function getSummary(): string
    {
        $parts = [];

        if ($this->firstResponseMinutes !== null) {
            $parts[] = Craft::t('csr', 'First reply within {duration}', [
                'duration' => self::humanDuration($this->firstResponseMinutes),
            ]);
        }

        if ($this->resolutionMinutes !== null) {
            $parts[] = Craft::t('csr', 'Resolved within {duration}', [
                'duration' => self::humanDuration($this->resolutionMinutes),
            ]);
        }

        return implode(', ', $parts);
    }

    /** @param int $minutes */
    public static function humanDuration(int $minutes): string
    {
        if ($minutes < 60) {
            return Craft::t('csr', '{n, plural, =1{1 minute} other{# minutes}}', ['n' => $minutes]);
        }

        if ($minutes < 60 * 24) {
            $hours = round($minutes / 60, 1);

            return Craft::t('csr', '{n, plural, =1{1 hour} other{# hours}}', ['n' => $hours]);
        }

        $days = round($minutes / (60 * 24), 1);

        return Craft::t('csr', '{n, plural, =1{1 day} other{# days}}', ['n' => $days]);
    }

    /** @return array<string, string> Priorities as a checkbox list. */
    public static function priorityOptions(): array
    {
        return Priority::options();
    }

    public function getCpEditUrl(): string
    {
        return UrlHelper::cpUrl('csr/settings/slas/' . ($this->id ?? 'new'));
    }

    /** @return array<string, mixed> */
    public function getConfig(): array
    {
        return [
            'name' => $this->name,
            'handle' => $this->handle,
            'description' => $this->description ?: null,
            'enabled' => $this->enabled,
            'conditions' => $this->_conditions,
            'firstResponseMinutes' => $this->firstResponseMinutes,
            'resolutionMinutes' => $this->resolutionMinutes,
            'businessHoursOnly' => $this->businessHoursOnly,
            'escalateAfterMinutes' => $this->escalateAfterMinutes,
            'escalateToTeamUid' => $this->escalateToTeamUid ?: null,
            'notifyOnBreach' => $this->notifyOnBreach,
            'sortOrder' => (int)$this->sortOrder,
        ];
    }
}
