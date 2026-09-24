<?php

declare(strict_types=1);

namespace justinholtweb\csr\models;

use craft\base\Model;
use craft\helpers\StringHelper;
use justinholtweb\csr\enums\Priority;
use ReflectionNamedType;
use ReflectionProperty;

/**
 * Everything CSR can be told, in one model.
 *
 * Two conventions here are load-bearing rather than stylistic:
 *
 * **Nothing is `required`.** A `required` rule on any single setting makes
 * `savePluginSettings()` fail wholesale, so a fresh install cannot save *any* setting until that
 * one is filled in. Values are validated for correctness when they are present instead.
 *
 * **Scalars are coerced on the way in.** Craft's number inputs post an empty string when an author
 * clears them, and assigning `''` to a typed `int` property is a `TypeError` that fatals the save
 * rather than failing validation. {@see self::setAttributes()} casts against each property's
 * declared type and keeps the default when a value cannot be read.
 */
class Settings extends Model
{
    public const ASSIGN_NONE = 'none';
    public const ASSIGN_ROUND_ROBIN = 'roundRobin';
    public const ASSIGN_LEAST_BUSY = 'leastBusy';

    public const CAPTCHA_NONE = 'none';
    public const CAPTCHA_TURNSTILE = 'turnstile';
    public const CAPTCHA_RECAPTCHA = 'recaptcha';

    // The knowledge base
    // -------------------------------------------------------------------------

    /** What the knowledge base is called on the front end. */
    public string $kbName = 'Help Center';

    public bool $kbEnabled = true;

    /** Articles per page in the built-in index and search results. */
    public int $articlesPerPage = 20;

    /** Show the "was this helpful?" control under an article. */
    public bool $showRatings = true;

    /**
     * Ask a reader who said "no" what they were actually looking for.
     *
     * The single most useful free-text field in the whole plugin: it is asked at the exact moment
     * somebody is both motivated to answer and holding the sentence the article should have
     * contained.
     */
    public bool $askWhyOnUnhelpful = true;

    /** How many related articles to show when an author has not chosen any by hand. */
    public int $relatedCount = 4;

    /** Days of history behind "popular articles" and "top searches". */
    public int $popularWindowDays = 30;

    // Search
    // -------------------------------------------------------------------------

    public bool $trackSearches = true;

    public bool $trackViews = true;

    /** Shorter than this is not a search, it is somebody still typing. */
    public int $searchMinLength = 2;

    /** Suggestions shown in the live search dropdown. */
    public int $searchSuggestionLimit = 8;

    /**
     * How long individual searches are kept.
     *
     * Zero means forever. The rows carry no personal data beyond a salted session hash, but a
     * table nobody prunes is still a table that outgrows its usefulness.
     */
    public int $searchRetentionDays = 365;

    // The support form
    // -------------------------------------------------------------------------

    public bool $supportEnabled = true;

    /** Where the built-in support form lives, if CSR is routing it. */
    public string $supportPath = 'support';

    /** Where the built-in knowledge base index lives on sites that have not set their own. */
    public string $kbPath = 'kb';

    /** Whether somebody without an account may raise a ticket. */
    public bool $allowGuestTickets = true;

    /** Show likely answers as the subject is typed, before the form can be submitted. */
    public bool $deflectionEnabled = true;

    /** Characters typed before CSR goes looking. Below this every query matches everything. */
    public int $deflectionMinChars = 8;

    public int $deflectionCount = 3;

    /**
     * Make the reader look at the suggestions before the submit button works.
     *
     * Off by default and deliberately so: a support form that argues with somebody in trouble is
     * worse than an extra ticket. Sites that turn it on get a two-second delay, not a wall.
     */
    public bool $deflectionRequired = false;

    public string $defaultPriority = Priority::Normal->value;

    /** Let the person raising the ticket choose how urgent it is. Mostly, they will say urgent. */
    public bool $allowPrioritySelection = false;

    // Attachments
    // -------------------------------------------------------------------------

    public bool $attachmentsEnabled = false;

    /** The volume uploads land in. Attachments stay off until one is chosen. */
    public ?string $attachmentVolumeUid = null;

    public string $attachmentSubpath = 'tickets';

    public string $allowedFileExtensions = 'png,jpg,jpeg,gif,webp,pdf,txt,log,csv,zip';

    public int $maxFileSizeMb = 10;

    public int $maxFilesPerReply = 3;

    // The desk
    // -------------------------------------------------------------------------

    public string $autoAssign = self::ASSIGN_NONE;

    /** Automatically close a resolved ticket after this many days. Zero leaves them resolved. */
    public int $closeResolvedAfterDays = 7;

    /** A reply on a closed ticket reopens it, if it arrives within this many days. */
    public int $reopenWithinDays = 30;

    public bool $satisfactionEnabled = false;

    /** Hours to wait after resolution before asking. Immediately is nagging. */
    public int $satisfactionDelayHours = 24;

    /** Delete tickets after this many days. Zero keeps them forever. */
    public int $ticketRetentionDays = 0;

    /** The number the first ticket gets. Nobody wants to be told they are ticket 1. */
    public int $ticketNumberStart = 1000;

    // Notifications
    // -------------------------------------------------------------------------

    public bool $notifyRequesterOnNew = true;

    public bool $notifyRequesterOnReply = true;

    public bool $notifyAgentsOnNew = true;

    public bool $notifyAgentOnReply = true;

    public bool $notifyOnSlaBreach = true;

    /** Send through the queue rather than in the request that raised the ticket. */
    public bool $queueNotifications = true;

    // The customer's own view
    // -------------------------------------------------------------------------

    public bool $portalEnabled = true;

    /** Where a requester reads and replies to their own ticket. */
    public string $portalPath = 'support/ticket';

    /** How long the link in a notification email stays usable, in days. Zero never expires. */
    public int $portalLinkDays = 0;

    // Spam
    // -------------------------------------------------------------------------

    /** The name of the field a robot cannot resist. */
    public string $honeypotField = 'csrCompanyUrl';

    /** A form completed faster than this was not read. Zero switches the check off. */
    public int $minSubmitSeconds = 3;

    /** Tickets one address may raise per hour. Zero is unlimited. */
    public int $rateLimit = 5;

    /** Replies one address may post from its ticket pages per hour. Zero is unlimited. */
    public int $replyRateLimit = 20;

    public string $captchaProvider = self::CAPTCHA_NONE;

    public ?string $captchaSiteKey = null;

    public ?string $captchaSecret = null;

    /** Whether the requester's IP address is stored on the ticket. */
    public bool $storeIpAddress = true;

    // SLAs
    // -------------------------------------------------------------------------

    public bool $slaEnabled = false;

    /** Which timezone the opening hours are expressed in. Empty means the system timezone. */
    public ?string $businessTimezone = null;

    // Pigeon
    // -------------------------------------------------------------------------

    /**
     * Hand the conversation half of a ticket to Pigeon when it is installed.
     *
     * CSR and Pigeon overlap on exactly one thing — a threaded back-and-forth with somebody who
     * may not have an account — and Pigeon does it better, with read receipts, guest tokens and an
     * inbox. When the bridge is on, raising a ticket opens a Pigeon thread, replies flow both
     * ways, and CSR keeps what it is actually for: the article that should have prevented the
     * ticket, and the clock on the promise to answer it.
     */
    public bool $pigeonBridge = false;

    // Values that arrive from editable tables
    // -------------------------------------------------------------------------

    /**
     * @var array<int, array<int, string>> Groups of words that mean the same thing.
     *
     * Held privately because Craft's editable table posts rows (`[['terms' => '…'], …]`) rather
     * than the flat list this is used as, so it needs a setter that normalises — and a private
     * property is not a Yii attribute, so it would silently never persist. {@see self::attributes()}
     * names it back.
     */
    private array $_synonyms = [];

    /** @var array<int, string> Addresses copied on every new ticket. */
    private array $_notificationRecipients = [];

    /**
     * @var array<string, array{open: string, close: string}|null> Opening hours by weekday name.
     *
     * Null for a day the desk is shut. Used only when a policy says `businessHoursOnly`.
     */
    private array $_businessHours = [];

    /** @var array<int, string> `Y-m-d` dates the desk is shut regardless of the weekday. */
    private array $_holidays = [];

    public const WEEKDAYS = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];

    /**
     * Names the getter-backed settings so Craft persists them.
     *
     * Craft saves plugin settings by iterating `attributes()`, which only reports *public*
     * properties. A setting backed by a getter/setter pair — the usual way to normalise editable
     * table rows — is otherwise dropped on every save with no error anywhere.
     */
    public function attributes(): array
    {
        return array_merge(parent::attributes(), [
            'synonyms',
            'notificationRecipients',
            'businessHours',
            'holidays',
        ]);
    }

    /**
     * Casts posted values to each property's declared type before assigning.
     *
     * Craft's number inputs post `''` when cleared, and `$settings->rateLimit = ''` on a typed
     * `int` property is a `TypeError` — a fatal mid-save, not a validation failure. Lightswitches
     * post `'1'`/`''`, which is fine for `bool` but not for the `?string` fields, where an empty
     * string and "not set" mean different things.
     *
     * @param array<string, mixed> $values
     */
    public function setAttributes($values, $safeOnly = true): void
    {
        foreach ($values as $name => $value) {
            if (!property_exists($this, $name) || !is_scalar($value)) {
                continue;
            }

            $property = new ReflectionProperty($this, $name);
            $type = $property->getType();

            if (!$type instanceof ReflectionNamedType) {
                continue;
            }

            $values[$name] = match ($type->getName()) {
                'int' => $value === '' ? ($property->getDefaultValue() ?? 0) : (int)$value,
                'float' => $value === '' ? ($property->getDefaultValue() ?? 0.0) : (float)$value,
                'bool' => (bool)$value,
                'string' => $value === '' && $type->allowsNull() ? null : (string)$value,
                default => $value,
            };
        }

        parent::setAttributes($values, $safeOnly);
    }

    // Synonyms
    // -------------------------------------------------------------------------

    /** @return array<int, array<int, string>> */
    public function getSynonyms(): array
    {
        return $this->_synonyms;
    }

    /**
     * Accepts the stored list of lists, or the rows an editable table posts.
     *
     * A row is a comma-separated line — `refund, money back, chargeback` — because that is how
     * somebody thinks about synonyms, and asking them to type JSON would mean nobody ever set any.
     */
    public function setSynonyms(mixed $value): void
    {
        $groups = [];

        foreach (is_array($value) ? $value : [] as $row) {
            $terms = match (true) {
                is_array($row) && isset($row['terms']) => explode(',', (string)$row['terms']),
                is_array($row) => $row,
                default => explode(',', (string)$row),
            };

            $terms = array_values(array_unique(array_filter(array_map(
                static fn($term) => StringHelper::toLowerCase(trim((string)$term)),
                $terms,
            ), static fn(string $term) => $term !== '')));

            // A group of one is a word that is a synonym for itself, which is every word.
            if (count($terms) > 1) {
                $groups[] = $terms;
            }
        }

        $this->_synonyms = $groups;
    }

    // Notification recipients
    // -------------------------------------------------------------------------

    /** @return array<int, string> */
    public function getNotificationRecipients(): array
    {
        return $this->_notificationRecipients;
    }

    public function setNotificationRecipients(mixed $value): void
    {
        $addresses = is_string($value) ? preg_split('/[\s,;]+/', $value) : (is_array($value) ? $value : []);
        $clean = [];

        foreach ($addresses ?: [] as $address) {
            $address = is_array($address) ? (string)($address['email'] ?? '') : trim((string)$address);

            if ($address !== '' && filter_var($address, FILTER_VALIDATE_EMAIL)) {
                $clean[$address] = true;
            }
        }

        $this->_notificationRecipients = array_keys($clean);
    }

    // Opening hours
    // -------------------------------------------------------------------------

    /** @return array<string, array{open: string, close: string}|null> */
    public function getBusinessHours(): array
    {
        return $this->_businessHours;
    }

    public function setBusinessHours(mixed $value): void
    {
        $hours = [];

        foreach (self::WEEKDAYS as $day) {
            $row = is_array($value) ? ($value[$day] ?? null) : null;

            if (!is_array($row)) {
                continue;
            }

            // A lightswitch says whether the desk is open; without it, two filled times mean open.
            $isOpen = array_key_exists('enabled', $row) ? (bool)$row['enabled'] : true;
            $open = trim((string)($row['open'] ?? ''));
            $close = trim((string)($row['close'] ?? ''));

            if ($isOpen && $open !== '' && $close !== '') {
                $hours[$day] = ['open' => $open, 'close' => $close];
            }
        }

        $this->_businessHours = $hours;
    }

    /** The default: nine to five, Monday to Friday. Used when nobody has set anything. */
    public function getEffectiveBusinessHours(): array
    {
        if ($this->_businessHours !== []) {
            return $this->_businessHours;
        }

        return [
            'monday' => ['open' => '09:00', 'close' => '17:00'],
            'tuesday' => ['open' => '09:00', 'close' => '17:00'],
            'wednesday' => ['open' => '09:00', 'close' => '17:00'],
            'thursday' => ['open' => '09:00', 'close' => '17:00'],
            'friday' => ['open' => '09:00', 'close' => '17:00'],
        ];
    }

    // Holidays
    // -------------------------------------------------------------------------

    /** @return array<int, string> */
    public function getHolidays(): array
    {
        return $this->_holidays;
    }

    public function setHolidays(mixed $value): void
    {
        $dates = is_string($value) ? preg_split('/[\s,;]+/', $value) : (is_array($value) ? $value : []);
        $clean = [];

        foreach ($dates ?: [] as $date) {
            $date = is_array($date) ? (string)($date['date'] ?? '') : trim((string)$date);

            if ($date !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1) {
                $clean[$date] = true;
            }
        }

        $this->_holidays = array_keys($clean);
    }

    // Derived
    // -------------------------------------------------------------------------

    /** @return array<int, string> Lower-cased, dot-free extensions. */
    public function getAllowedExtensions(): array
    {
        $extensions = array_filter(array_map(
            static fn(string $extension) => StringHelper::toLowerCase(trim($extension, " \t\n\r\0\x0B.")),
            explode(',', $this->allowedFileExtensions),
        ));

        return array_values(array_unique($extensions));
    }

    public function getMaxFileSizeBytes(): int
    {
        return max(0, $this->maxFileSizeMb) * 1024 * 1024;
    }

    public function getDefaultPriority(): Priority
    {
        return Priority::fromString($this->defaultPriority);
    }

    public function rules(): array
    {
        return [
            [
                [
                    'articlesPerPage', 'relatedCount', 'popularWindowDays', 'searchMinLength',
                    'searchSuggestionLimit', 'searchRetentionDays', 'deflectionMinChars', 'deflectionCount',
                    'maxFileSizeMb', 'maxFilesPerReply', 'closeResolvedAfterDays', 'reopenWithinDays',
                    'satisfactionDelayHours', 'ticketRetentionDays', 'ticketNumberStart', 'portalLinkDays',
                    'minSubmitSeconds', 'rateLimit', 'replyRateLimit',
                ],
                'integer',
                'min' => 0,
            ],
            [['articlesPerPage', 'searchSuggestionLimit', 'ticketNumberStart'], 'integer', 'min' => 1],
            [['kbName', 'kbPath', 'supportPath', 'portalPath', 'honeypotField', 'attachmentSubpath'], 'string', 'max' => 255],
            [['defaultPriority'], 'in', 'range' => array_column(Priority::cases(), 'value')],
            [['autoAssign'], 'in', 'range' => [self::ASSIGN_NONE, self::ASSIGN_ROUND_ROBIN, self::ASSIGN_LEAST_BUSY]],
            [['captchaProvider'], 'in', 'range' => [self::CAPTCHA_NONE, self::CAPTCHA_TURNSTILE, self::CAPTCHA_RECAPTCHA]],
            // Only checked when a provider is chosen — see the note at the top of the class about
            // why nothing here may simply be `required`.
            [
                ['captchaSiteKey', 'captchaSecret'],
                'required',
                'when' => fn(self $settings) => $settings->captchaProvider !== self::CAPTCHA_NONE,
            ],
            [['businessTimezone'], 'in', 'range' => timezone_identifiers_list(), 'skipOnEmpty' => true],
            [['honeypotField'], 'match', 'pattern' => '/^[a-zA-Z][a-zA-Z0-9_-]*$/'],
            // `p` and `token` are Craft's own query parameters, and `action` is its controller
            // route. A honeypot named any of them is rejected before a controller ever runs.
            // `in` with `not`, not `notIn`: Yii has no validator of the latter name, and asking
            // for one throws rather than failing validation.
            [['honeypotField'], 'in', 'not' => true, 'range' => ['p', 'token', 'action', 'siteId']],
        ];
    }
}
