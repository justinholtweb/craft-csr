<?php

declare(strict_types=1);

namespace justinholtweb\csr\models;

use Craft;
use craft\base\Model;
use craft\helpers\App;
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

    public const UNVERIFIED_NEW_TICKET = 'newTicket';
    public const UNVERIFIED_REJECT = 'reject';

    public const IMAP_SSL = 'ssl';
    public const IMAP_TLS = 'tls';
    public const IMAP_NONE = 'none';

    public const IMAP_MARK_SEEN = 'seen';
    public const IMAP_DELETE = 'delete';

    public const DIGEST_DAILY = 'daily';
    public const DIGEST_WEEKLY = 'weekly';

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

    // Email in
    // -------------------------------------------------------------------------

    /**
     * Turn email into tickets and replies (Pro).
     *
     * Off by default: until a provider is pointed at the webhook, or a mailbox is set up for the
     * poll, there is nothing to receive — and the reply address is only put on outgoing mail once
     * this is on, so a site that never set it up never tells a customer to reply to a mailbox
     * nobody reads.
     */
    public bool $inboundEnabled = false;

    /**
     * The desk's address — `support@example.com`, or `$CSR_INBOUND_ADDRESS`. Each ticket's reply
     * address is this with a tag: `support+<token>@example.com`.
     */
    public string $inboundAddress = '';

    /** Whether an email that is not a reply to a ticket raises a new one. */
    public bool $inboundCreateTickets = true;

    /**
     * What to do with a reply to a ticket from somebody other than its customer.
     *
     * `newTicket` treats it as a new conversation (a colleague writing in about the same thing);
     * `reject` drops it. Never "add it to the ticket": the reply address is in every email the
     * customer was sent, and a forwarded email must not let a third party write into their
     * conversation.
     */
    public string $inboundUnverifiedSender = self::UNVERIFIED_NEW_TICKET;

    /** Emails one sender may send in an hour before the rest are dropped. Zero is unlimited. */
    public int $inboundRateLimit = 20;

    /**
     * Keep attachments that arrive by email, under the support form's attachment settings — the
     * same volume, extensions, size and count.
     */
    public bool $inboundAttachments = true;

    /**
     * Seconds either side of now a signed timestamp may be (Mailgun, SendGrid). Long enough for
     * clock drift and a provider's own retry delay, short enough that a captured post is useless.
     */
    public int $inboundReplayWindow = 300;

    /** Other addresses the desk sends as. Mail *from* them is never turned into a ticket. */
    public string $inboundIgnoreAddresses = '';

    public string $postmarkUsername = '';

    public string $postmarkPassword = '';

    public string $mailgunSigningKey = '';

    /** The public key from SendGrid's Parse security policy, as base64 or PEM. */
    public string $sendgridPublicKey = '';

    public string $sendgridUsername = '';

    public string $sendgridPassword = '';

    /** For `php craft csr/inbound/poll`. Needs PHP's imap extension, which most hosts lack. */
    public string $imapHost = '';

    public int $imapPort = 993;

    public string $imapEncryption = self::IMAP_SSL;

    public string $imapUsername = '';

    public string $imapPassword = '';

    public string $imapMailbox = 'INBOX';

    /** What happens to a message once it has been taken in: marked read, or deleted. */
    public string $imapAfterImport = self::IMAP_MARK_SEEN;

    // The digest
    // -------------------------------------------------------------------------

    /** Email the desk a summary of open tickets and SLA breaches on a schedule. */
    public bool $digestEnabled = false;

    public string $digestFrequency = self::DIGEST_DAILY;

    /** ISO day of the week for a weekly digest: 1 is Monday, 7 is Sunday. */
    public int $digestWeekday = 1;

    /**
     * Hour of the day, 0–23, in the system time zone, after which the digest for the day or week
     * becomes due. "After", not "at": a site whose cron missed the hour still sends later the
     * same period, once.
     */
    public int $digestHour = 8;

    /** Addresses separated by commas or new lines, or an environment variable holding them. */
    public string $digestRecipients = '';

    /** Also send it to everybody who can answer tickets. */
    public bool $digestIncludeAgents = true;

    /**
     * Send even when nothing is new since the last digest. Off: a daily "nothing to report" is an
     * email people learn to stop opening.
     */
    public bool $digestSendWhenEmpty = false;

    /** Check the schedule at the end of web requests too, for sites without a cron job. */
    public bool $digestWebTrigger = true;

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

    /**
     * A setting that may be an environment variable, resolved — or `''` when it names one that is
     * not set.
     *
     * `App::parseEnv('$NAME')` hands back `'$NAME'` itself when the variable is missing, and a
     * secret that is literally the string `$MAILGUN_KEY` is a secret anybody reading the docs
     * knows. Treated as unset, the provider refuses everything instead.
     */
    public function env(string $attribute): string
    {
        $raw = trim((string)($this->$attribute ?? ''));

        if ($raw === '') {
            return '';
        }

        if (preg_match('/^\$(\w+)$/', $raw, $m) === 1 && App::env($m[1]) === null) {
            return '';
        }

        return trim((string)App::parseEnv($raw));
    }

    /** @return list<string> The extra addresses mail is never taken from. */
    public function getIgnoredAddresses(): array
    {
        $parts = preg_split('/[\s,;]+/', $this->env('inboundIgnoreAddresses'), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_values(array_filter(array_map(
            static fn(string $address) => filter_var($address, FILTER_VALIDATE_EMAIL) !== false ? strtolower($address) : '',
            $parts,
        )));
    }

    /**
     * The digest's own recipients, parsed: environment variable resolved, split on commas,
     * semicolons and whitespace, de-duplicated. Agents are added by the digest service.
     *
     * @param bool $invalid Return the entries that are *not* email addresses instead.
     * @return list<string>
     */
    public function recipientList(bool $invalid = false): array
    {
        $raw = $this->env('digestRecipients');
        $parts = preg_split('/[\s,;]+/', $raw, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $valid = [];
        $bad = [];

        foreach (array_unique($parts) as $part) {
            if (filter_var($part, FILTER_VALIDATE_EMAIL) !== false) {
                $valid[] = $part;
            } else {
                $bad[] = $part;
            }
        }

        return $invalid ? $bad : $valid;
    }

    public function validateRecipients(string $attribute): void
    {
        foreach ($this->recipientList(true) as $address) {
            $this->addError($attribute, Craft::t('csr', '“{address}” is not an email address.', [
                'address' => $address,
            ]));
        }
    }

    public function validateInboundAddress(string $attribute): void
    {
        $value = $this->env($attribute);

        if ($value !== '' && filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
            $this->addError($attribute, Craft::t('csr', 'The desk’s address must be an email address.'));
        } elseif (str_contains(explode('@', $value)[0], '+')) {
            $this->addError($attribute, Craft::t('csr', 'Use the plain address, without a +tag. CSR adds the tag for each ticket.'));
        }
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
            [['inboundUnverifiedSender'], 'in', 'range' => [self::UNVERIFIED_NEW_TICKET, self::UNVERIFIED_REJECT]],
            [['inboundReplayWindow'], 'integer', 'min' => 30, 'max' => 3600],
            [['imapPort'], 'integer', 'min' => 1, 'max' => 65535],
            [['imapEncryption'], 'in', 'range' => [self::IMAP_SSL, self::IMAP_TLS, self::IMAP_NONE]],
            [['imapAfterImport'], 'in', 'range' => [self::IMAP_MARK_SEEN, self::IMAP_DELETE]],
            [['inboundAddress'], 'validateInboundAddress'],
            [['digestFrequency'], 'in', 'range' => [self::DIGEST_DAILY, self::DIGEST_WEEKLY]],
            [['digestWeekday'], 'integer', 'min' => 1, 'max' => 7],
            [['digestHour'], 'integer', 'min' => 0, 'max' => 23],
            [['digestRecipients'], 'validateRecipients'],
        ];
    }
}
