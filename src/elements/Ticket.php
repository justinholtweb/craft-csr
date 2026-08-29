<?php

declare(strict_types=1);

namespace justinholtweb\csr\elements;

use Craft;
use craft\base\Element;
use craft\elements\db\ElementQueryInterface;
use craft\elements\User;
use craft\helpers\Db;
use craft\helpers\Html;
use craft\helpers\Json;
use craft\helpers\StringHelper;
use craft\helpers\UrlHelper;
use craft\models\FieldLayout;
use craft\models\Site;
use DateTime;
use justinholtweb\csr\elements\actions\SetStatus;
use justinholtweb\csr\elements\db\TicketQuery;
use justinholtweb\csr\enums\Priority;
use justinholtweb\csr\enums\StatusKind;
use justinholtweb\csr\enums\TicketSource;
use justinholtweb\csr\models\Attachment;
use justinholtweb\csr\models\Category;
use justinholtweb\csr\models\FormField;
use justinholtweb\csr\models\Reply;
use justinholtweb\csr\models\SlaPolicy;
use justinholtweb\csr\models\Status;
use justinholtweb\csr\models\Team;
use justinholtweb\csr\models\TicketEvent;
use justinholtweb\csr\Plugin;
use justinholtweb\csr\records\TicketRecord;

/**
 * Somebody asking for help.
 *
 * An element, for the same reasons an article is one — the index, search, the trash, relations,
 * permissions and the exporter are all things a support inbox needs and none of them are worth
 * rewriting — with three deliberate departures from how CSR treats articles:
 *
 * **It is not localized.** A ticket happens once, in one language, from one place. There is no
 * German translation of what somebody wrote at two in the morning. The site it came from is an
 * ordinary column, `submittedSiteId` — *not* `siteId`, which `craft\base\ElementTrait` already
 * declares, and a column shadowing it silently reassigns every ticket to the wrong site.
 *
 * **It survives its requester.** `requesterId` is `SET NULL` and the email address and name are
 * columns. Most people who write to support have no account; some of the ones who do close it.
 * Either way the conversation happened and has to stay readable.
 *
 * **It has a number as well as an ID.** Element IDs are shared with every other element in the
 * install, so they jump by hundreds between two tickets raised an hour apart, and a customer
 * quoting "ticket 4,182,001" is quoting something that looks made up. `number` is sequential and
 * per-install, allocated inside the same transaction as the insert.
 *
 * @property-read Status|null $ticketStatus
 * @property-read Category|null $category
 * @property-read Team|null $team
 * @property-read User|null $agent
 * @property-read Reply[] $replies
 * @property-read TicketEvent[] $events
 */
class Ticket extends Element
{
    public int $number = 0;
    public ?string $reference = null;
    public ?int $statusId = null;
    public string $priority = 'normal';
    public ?int $categoryId = null;
    public ?int $agentId = null;
    public ?int $teamId = null;
    public ?int $requesterId = null;
    public string $requesterEmail = '';
    public ?string $requesterName = null;
    public ?int $submittedSiteId = null;
    public string $source = 'web';
    public ?string $message = null;
    public ?int $slaPolicyId = null;
    public ?DateTime $firstResponseDue = null;
    public ?DateTime $resolutionDue = null;
    public ?DateTime $firstRespondedAt = null;
    public ?DateTime $resolvedAt = null;
    public ?DateTime $closedAt = null;
    public ?DateTime $lastReplyAt = null;
    public ?string $lastReplyBy = null;
    public bool $firstResponseBreached = false;
    public bool $resolutionBreached = false;
    public ?DateTime $escalatedAt = null;
    public ?DateTime $clockPausedAt = null;
    public int $pausedSeconds = 0;
    public int $replyCount = 0;
    public int $agentReplyCount = 0;
    public ?int $deflectionId = null;
    public ?int $pigeonThreadId = null;
    public ?int $satisfaction = null;
    public ?string $satisfactionComment = null;
    public bool $isSpam = false;
    public ?int $mergedIntoId = null;
    public ?string $ipAddress = null;
    public ?string $userAgent = null;
    public ?DateTime $submittedAt = null;

    /** @var array<string, mixed> Answers to the form's custom questions. */
    private array $_answers = [];

    private ?Status $_status = null;

    /**
     * @var int|null The `statusId` the memoized status was loaded for.
     *
     * A plain "loaded" flag is not enough: moving a ticket sets `statusId` and the cached model
     * then describes where the ticket *was*. Every automatic transition in the plugin reads the
     * status back immediately afterwards to decide what to do next, so a stale one is not a
     * display bug — it makes reopening fail to clear the resolution date.
     */
    private ?int $_statusLoadedFor = null;
    private bool $_statusLoaded = false;

    /** @var Reply[]|null */
    private ?array $_replies = null;

    /** @var TicketEvent[]|null */
    private ?array $_events = null;

    /** @var Attachment[]|null */
    private ?array $_attachments = null;

    // Identity
    // -------------------------------------------------------------------------

    public static function displayName(): string
    {
        return Craft::t('csr', 'Ticket');
    }

    public static function lowerDisplayName(): string
    {
        return Craft::t('csr', 'ticket');
    }

    public static function pluralDisplayName(): string
    {
        return Craft::t('csr', 'Tickets');
    }

    public static function pluralLowerDisplayName(): string
    {
        return Craft::t('csr', 'tickets');
    }

    public static function refHandle(): ?string
    {
        return 'csrTicket';
    }

    public static function hasTitles(): bool
    {
        return true;
    }

    public static function hasUris(): bool
    {
        return false;
    }

    /**
     * No.
     *
     * Craft's element statuses are enabled/disabled, and a ticket's status is a support process
     * with five or fifty states in it. Overloading one onto the other would mean a disabled
     * element and a closed ticket becoming the same idea, and they are not remotely the same idea.
     */
    public static function hasStatuses(): bool
    {
        return false;
    }

    public static function isLocalized(): bool
    {
        return false;
    }

    public static function hasDrafts(): bool
    {
        return false;
    }

    public static function find(): ElementQueryInterface
    {
        return new TicketQuery(static::class);
    }

    public function datetimeAttributes(): array
    {
        return array_merge(parent::datetimeAttributes(), [
            'firstResponseDue', 'resolutionDue', 'firstRespondedAt', 'resolvedAt', 'closedAt',
            'lastReplyAt', 'escalatedAt', 'clockPausedAt', 'submittedAt',
        ]);
    }

    public function getFieldLayout(): ?FieldLayout
    {
        return Craft::$app->getFields()->getLayoutByType(self::class);
    }

    protected function cpEditUrl(): ?string
    {
        return UrlHelper::cpUrl(sprintf('csr/tickets/%s', $this->getCanonicalId()));
    }

    public function getPostEditUrl(): ?string
    {
        return UrlHelper::cpUrl('csr/tickets');
    }

    public function getUiLabel(): string
    {
        return sprintf('#%s %s', $this->number, (string)$this->title);
    }

    // The people
    // -------------------------------------------------------------------------

    public function getRequester(): ?User
    {
        return $this->requesterId !== null ? Craft::$app->getUsers()->getUserById($this->requesterId) : null;
    }

    public function getRequesterLabel(): string
    {
        return $this->requesterName ?: ($this->getRequester()?->getFriendlyName() ?: $this->requesterEmail);
    }

    /**
     * The name to greet them by in an email.
     *
     * Falls back to the whole name and then to "there", because "Hi ," is worse than a slightly
     * impersonal greeting, and a form that only asked for an email address is perfectly reasonable.
     */
    public function getGreetingName(): string
    {
        $name = trim($this->requesterName ?: (string)$this->getRequester()?->getFriendlyName());

        if ($name === '') {
            return Craft::t('csr', 'there');
        }

        return explode(' ', $name)[0];
    }

    public function getAgent(): ?User
    {
        return $this->agentId !== null ? Craft::$app->getUsers()->getUserById($this->agentId) : null;
    }

    public function getTeam(): ?Team
    {
        return $this->teamId !== null ? Plugin::getInstance()->teams->getTeamById($this->teamId) : null;
    }

    public function getSubmittedSite(): ?Site
    {
        return $this->submittedSiteId !== null
            ? Craft::$app->getSites()->getSiteById($this->submittedSiteId)
            : null;
    }

    // Where it is
    // -------------------------------------------------------------------------

    /**
     * The ticket's place in the support process.
     *
     * **Not** `getStatus()`. `craft\base\Element::getStatus(): ?string` has a fixed signature, so
     * an override returning a model is a compile-time fatal the moment the class autoloads —
     * which disables the whole plugin, nowhere near the call site. Same family as the
     * `Model::load()`, `Model::rules()` and `Model::toArray()` collisions.
     */
    public function getTicketStatus(): ?Status
    {
        if (!$this->_statusLoaded || $this->_statusLoadedFor !== $this->statusId) {
            $this->_statusLoaded = true;
            $this->_statusLoadedFor = $this->statusId;
            $this->_status = Plugin::getInstance()->statuses->getStatusById($this->statusId);
        }

        return $this->_status;
    }

    public function getStatusKind(): StatusKind
    {
        return $this->getTicketStatus()?->getKind() ?? StatusKind::Open;
    }

    public function getPriority(): Priority
    {
        return Priority::fromString($this->priority);
    }

    public function getSource(): TicketSource
    {
        return TicketSource::fromString($this->source);
    }

    // Templates read these rather than `ticket.priority.label`: `priority` and `source` are
    // string columns, and Twig resolves a property before it tries the getter of the same name —
    // so the enum is never reached and the template throws on a string.

    public function getPriorityLabel(): string
    {
        return $this->getPriority()->label();
    }

    public function getPriorityColor(): string
    {
        return $this->getPriority()->color();
    }

    public function getSourceLabel(): string
    {
        return $this->getSource()->label();
    }

    public function getCategory(): ?Category
    {
        return $this->categoryId !== null
            ? Plugin::getInstance()->categories->getCategoryById($this->categoryId)
            : null;
    }

    public function getSlaPolicy(): ?SlaPolicy
    {
        return $this->slaPolicyId !== null
            ? Plugin::getInstance()->slas->getPolicyById($this->slaPolicyId)
            : null;
    }

    public function getIsOpen(): bool
    {
        return $this->getStatusKind()->isOpen();
    }

    /** Nobody at the desk has said anything yet. The queue that matters most. */
    public function getIsAwaitingFirstResponse(): bool
    {
        return $this->firstRespondedAt === null && $this->getIsOpen();
    }

    /** The customer replied last, so the ball is with the desk. */
    public function getIsAwaitingUs(): bool
    {
        return $this->getIsOpen() && $this->lastReplyBy !== 'agent';
    }

    // SLA
    // -------------------------------------------------------------------------

    public function getHasSla(): bool
    {
        return $this->firstResponseDue !== null || $this->resolutionDue !== null;
    }

    /**
     * Seconds until the first response is due. Negative means it is late.
     *
     * Null when there is no such promise, or when it has already been kept — a ticket somebody has
     * replied to has no first-response countdown, and showing one that ticks past zero after the
     * work was done is how an SLA dashboard loses the desk's trust.
     */
    public function getFirstResponseRemaining(): ?int
    {
        if ($this->firstResponseDue === null || $this->firstRespondedAt !== null) {
            return null;
        }

        return $this->firstResponseDue->getTimestamp() - time();
    }

    public function getResolutionRemaining(): ?int
    {
        if ($this->resolutionDue === null || $this->resolvedAt !== null) {
            return null;
        }

        return $this->resolutionDue->getTimestamp() - time();
    }

    /**
     * Whether either promise is currently broken.
     *
     * Computed at read time as well as stamped by the sweep, so a ticket whose deadline passed
     * twenty minutes ago is already red on a site whose cron is broken.
     */
    public function getIsBreaching(): bool
    {
        if ($this->firstResponseBreached || $this->resolutionBreached) {
            return true;
        }

        $first = $this->getFirstResponseRemaining();
        $resolution = $this->getResolutionRemaining();

        return ($first !== null && $first < 0) || ($resolution !== null && $resolution < 0);
    }

    /** Within the last tenth of the window. The colour that means "pick this up now". */
    public function getIsAtRisk(): bool
    {
        $remaining = $this->getFirstResponseRemaining() ?? $this->getResolutionRemaining();

        return $remaining !== null && $remaining > 0 && $remaining < 3600;
    }

    // The conversation
    // -------------------------------------------------------------------------

    /** @return Reply[] */
    public function getReplies(bool $includeNotes = true): array
    {
        $this->_replies ??= $this->id !== null
            ? Plugin::getInstance()->replies->getReplies((int)$this->id)
            : [];

        return $includeNotes
            ? $this->_replies
            : array_values(array_filter($this->_replies, static fn(Reply $reply) => !$reply->isNote));
    }

    /**
     * What the *customer* is allowed to see.
     *
     * A separate call rather than a flag on the template, because an internal note reaching the
     * requester is the one bug in this plugin that cannot be walked back. The portal never asks
     * for all the replies and filters; it asks for these.
     *
     * @return Reply[]
     */
    public function getPublicReplies(): array
    {
        return $this->getReplies(false);
    }

    /** @return TicketEvent[] */
    public function getEvents(): array
    {
        return $this->_events ??= $this->id !== null
            ? Plugin::getInstance()->tickets->getEvents((int)$this->id)
            : [];
    }

    /** @return Attachment[] */
    public function getAttachments(): array
    {
        return $this->_attachments ??= $this->id !== null
            ? Plugin::getInstance()->tickets->getAttachments((int)$this->id)
            : [];
    }

    // Answers
    // -------------------------------------------------------------------------

    /** @return array<string, mixed> */
    public function getAnswers(): array
    {
        return $this->_answers;
    }

    /** Accepts the JSON the column holds or an array from the submit controller. */
    public function setAnswers(mixed $value): void
    {
        if (is_string($value)) {
            $value = Json::decodeIfJson($value);
        }

        $this->_answers = is_array($value) ? $value : [];
    }

    public function getAnswer(string $handle): mixed
    {
        return $this->_answers[$handle] ?? null;
    }

    /**
     * Every answer paired with the question that produced it, in the form's order.
     *
     * Answers are stored by handle, and a handle is not a question — "affectedOrders: 3" belongs
     * next to "How many orders are affected?". Questions since removed from the form are still
     * listed, labelled by handle, because throwing away what somebody told you because the form
     * changed afterwards is worse than an ugly label.
     *
     * @return array<int, array{field: FormField|null, label: string, value: mixed, display: string}>
     */
    public function getAnsweredQuestions(): array
    {
        $answered = [];
        $seen = [];

        foreach (Plugin::getInstance()->forms->getAllFields() as $field) {
            if (!array_key_exists($field->handle, $this->_answers)) {
                continue;
            }

            $seen[$field->handle] = true;
            $value = $this->_answers[$field->handle];

            $answered[] = [
                'field' => $field,
                'label' => $field->name,
                'value' => $value,
                'display' => $field->formatValue($value),
            ];
        }

        foreach ($this->_answers as $handle => $value) {
            if (isset($seen[$handle])) {
                continue;
            }

            $answered[] = [
                'field' => null,
                'label' => $handle,
                'value' => $value,
                'display' => is_array($value) ? implode(', ', array_map('strval', $value)) : (string)$value,
            ];
        }

        return $answered;
    }

    // The requester's own link
    // -------------------------------------------------------------------------

    /**
     * Where the requester reads and replies to their own ticket.
     *
     * The parameter is `csrRef`, not `token`: Craft reserves `token` for preview and share links,
     * and a request carrying one it does not recognise is rejected in
     * `craft\web\Application::init()` before any controller runs — so the link in every
     * notification email would 400 with "Invalid token" and nothing in CSR would be reached.
     */
    public function getPortalUrl(): ?string
    {
        $settings = Plugin::getInstance()->getSettings();

        if ($this->reference === null || !$settings->portalEnabled) {
            return null;
        }

        return UrlHelper::siteUrl(
            $settings->portalPath,
            ['csrRef' => $this->reference],
            null,
            $this->submittedSiteId,
        );
    }

    // Saving
    // -------------------------------------------------------------------------

    protected function defineRules(): array
    {
        $rules = parent::defineRules();

        $rules[] = [['requesterEmail'], 'required'];
        $rules[] = [['requesterEmail'], 'email'];
        $rules[] = [['requesterEmail', 'requesterName'], 'string', 'max' => 255];
        $rules[] = [['statusId', 'categoryId', 'agentId', 'teamId', 'requesterId', 'submittedSiteId'], 'integer'];
        $rules[] = [['priority'], 'in', 'range' => array_column(Priority::cases(), 'value')];
        $rules[] = [['source'], 'in', 'range' => array_column(TicketSource::cases(), 'value')];
        $rules[] = [['satisfaction'], 'integer', 'min' => 1, 'max' => 5];
        $rules[] = [['title'], 'required'];

        return $rules;
    }

    public function beforeSave(bool $isNew): bool
    {
        if ($isNew) {
            $this->submittedAt ??= new DateTime();
            // 32 random characters, not a UUID: the column is `char(32)` and a UUID is 36 with
            // its hyphens, which MySQL truncates in loose mode — quietly breaking every link in
            // every notification email — and refuses outright in strict mode.
            $this->reference ??= StringHelper::randomString(32);
            $this->statusId ??= Plugin::getInstance()->statuses->getDefaultStatus()?->id;

            if ($this->number === 0) {
                $this->number = Plugin::getInstance()->tickets->allocateNumber();
            }
        }

        return parent::beforeSave($isNew);
    }

    public function afterSave(bool $isNew): void
    {
        $record = TicketRecord::findOne($this->id) ?? new TicketRecord();

        if ($record->getIsNewRecord()) {
            $record->id = $this->id;
        }

        $record->number = $this->number;
        $record->reference = $this->reference;
        $record->statusId = $this->statusId;
        $record->priority = $this->priority;
        $record->categoryId = $this->categoryId;
        $record->agentId = $this->agentId;
        $record->teamId = $this->teamId;
        $record->requesterId = $this->requesterId;
        $record->requesterEmail = $this->requesterEmail;
        $record->requesterName = $this->requesterName;
        $record->submittedSiteId = $this->submittedSiteId;
        $record->source = $this->source;
        $record->message = $this->message;
        $record->answers = $this->_answers === [] ? null : Json::encode($this->_answers);
        $record->slaPolicyId = $this->slaPolicyId;
        $record->firstResponseDue = Db::prepareDateForDb($this->firstResponseDue);
        $record->resolutionDue = Db::prepareDateForDb($this->resolutionDue);
        $record->firstRespondedAt = Db::prepareDateForDb($this->firstRespondedAt);
        $record->resolvedAt = Db::prepareDateForDb($this->resolvedAt);
        $record->closedAt = Db::prepareDateForDb($this->closedAt);
        $record->lastReplyAt = Db::prepareDateForDb($this->lastReplyAt);
        $record->lastReplyBy = $this->lastReplyBy;
        $record->firstResponseBreached = $this->firstResponseBreached;
        $record->resolutionBreached = $this->resolutionBreached;
        $record->escalatedAt = Db::prepareDateForDb($this->escalatedAt);
        $record->clockPausedAt = Db::prepareDateForDb($this->clockPausedAt);
        $record->pausedSeconds = $this->pausedSeconds;
        $record->replyCount = $this->replyCount;
        $record->agentReplyCount = $this->agentReplyCount;
        $record->deflectionId = $this->deflectionId;
        $record->pigeonThreadId = $this->pigeonThreadId;
        $record->satisfaction = $this->satisfaction;
        $record->satisfactionComment = $this->satisfactionComment;
        $record->isSpam = $this->isSpam;
        $record->mergedIntoId = $this->mergedIntoId;
        $record->ipAddress = $this->ipAddress;
        $record->userAgent = $this->userAgent;
        $record->submittedAt = Db::prepareDateForDb($this->submittedAt);
        $record->save(false);

        parent::afterSave($isNew);
    }

    // Search
    // -------------------------------------------------------------------------

    protected static function defineSearchableAttributes(): array
    {
        return ['title', 'requesterName', 'requesterEmail', 'message', 'number'];
    }

    // Index
    // -------------------------------------------------------------------------

    protected static function defineSources(string $context): array
    {
        $sources = [
            [
                'key' => '*',
                'label' => Craft::t('csr', 'All tickets'),
                'criteria' => ['isSpam' => false],
                'defaultSort' => ['csr_tickets.submittedAt', 'desc'],
            ],
            [
                'key' => 'open',
                'label' => Craft::t('csr', 'Open'),
                'criteria' => ['isSpam' => false, 'openOnly' => true],
                'defaultSort' => ['csr_tickets.submittedAt', 'desc'],
            ],
            [
                'key' => 'unanswered',
                'label' => Craft::t('csr', 'Awaiting a first reply'),
                'criteria' => ['isSpam' => false, 'openOnly' => true, 'awaitingFirstResponse' => true],
                'defaultSort' => ['csr_tickets.submittedAt', 'asc'],
            ],
            [
                'key' => 'breaching',
                'label' => Craft::t('csr', 'Late'),
                'criteria' => ['isSpam' => false, 'openOnly' => true, 'breaching' => true],
                'defaultSort' => ['csr_tickets.firstResponseDue', 'asc'],
            ],
            [
                'key' => 'mine',
                'label' => Craft::t('csr', 'Assigned to me'),
                'criteria' => ['isSpam' => false, 'openOnly' => true, 'agentId' => Craft::$app->getUser()->getId()],
                'defaultSort' => ['csr_tickets.submittedAt', 'desc'],
            ],
            [
                'key' => 'unassigned',
                'label' => Craft::t('csr', 'Unassigned'),
                // `false`, not `null`: null means "do not filter" to every element query in Craft.
                'criteria' => ['isSpam' => false, 'openOnly' => true, 'agentId' => false],
                'defaultSort' => ['csr_tickets.submittedAt', 'desc'],
            ],
        ];

        $statuses = Plugin::getInstance()->statuses->getAllStatuses();

        if ($statuses !== []) {
            $sources[] = ['heading' => Craft::t('csr', 'Status')];

            foreach ($statuses as $status) {
                $sources[] = [
                    'key' => 'status:' . $status->uid,
                    'label' => $status->name,
                    'criteria' => ['isSpam' => false, 'statusId' => $status->id],
                    'data' => ['handle' => $status->handle, 'color' => $status->color],
                    'defaultSort' => ['csr_tickets.submittedAt', 'desc'],
                ];
            }
        }

        $sources[] = ['heading' => Craft::t('csr', 'Elsewhere')];
        $sources[] = [
            'key' => 'spam',
            'label' => Craft::t('csr', 'Marked as spam'),
            'criteria' => ['isSpam' => true],
            'defaultSort' => ['csr_tickets.submittedAt', 'desc'],
        ];

        return $sources;
    }

    protected static function defineActions(string $source): array
    {
        $actions = parent::defineActions($source);

        if (Craft::$app->getUser()->checkPermission(Plugin::PERMISSION_MANAGE_TICKETS)) {
            $actions[] = SetStatus::class;
        }

        return $actions;
    }

    protected static function defineTableAttributes(): array
    {
        return [
            'number' => ['label' => Craft::t('csr', 'Number')],
            'requester' => ['label' => Craft::t('csr', 'Requester')],
            'ticketStatus' => ['label' => Craft::t('csr', 'Status')],
            'priority' => ['label' => Craft::t('csr', 'Priority')],
            'category' => ['label' => Craft::t('csr', 'Category')],
            'agent' => ['label' => Craft::t('csr', 'Agent')],
            'team' => ['label' => Craft::t('csr', 'Team')],
            'sla' => ['label' => Craft::t('csr', 'Due')],
            'replyCount' => ['label' => Craft::t('csr', 'Replies')],
            'lastReplyAt' => ['label' => Craft::t('csr', 'Last activity')],
            'submittedAt' => ['label' => Craft::t('csr', 'Raised')],
            'satisfaction' => ['label' => Craft::t('csr', 'Rating')],
            'submittedSite' => ['label' => Craft::t('app', 'Site')],
        ];
    }

    protected static function defineDefaultTableAttributes(string $source): array
    {
        return ['number', 'requester', 'ticketStatus', 'priority', 'agent', 'sla', 'submittedAt'];
    }

    protected static function defineSortOptions(): array
    {
        return [
            'csr_tickets.number' => Craft::t('csr', 'Number'),
            'title' => Craft::t('csr', 'Subject'),
            'csr_tickets.submittedAt' => Craft::t('csr', 'Raised'),
            'csr_tickets.lastReplyAt' => Craft::t('csr', 'Last activity'),
            'csr_tickets.firstResponseDue' => Craft::t('csr', 'First response due'),
            'csr_tickets.resolutionDue' => Craft::t('csr', 'Resolution due'),
            'csr_tickets.priority' => Craft::t('csr', 'Priority'),
        ];
    }

    protected function attributeHtml(string $attribute): string
    {
        return match ($attribute) {
            'number' => Html::tag('span', '#' . $this->number, ['class' => 'code']),
            'requester' => $this->requesterHtml(),
            'ticketStatus' => $this->statusHtml(),
            'priority' => Html::tag('span', '', ['class' => ['status', $this->getPriority()->color()]])
                . Html::tag('span', Html::encode($this->getPriority()->label())),
            'category' => Html::encode((string)$this->getCategory()?->name),
            'agent' => Html::encode((string)($this->getAgent()?->getFriendlyName()
                ?? Craft::t('csr', 'Unassigned'))),
            'team' => Html::encode((string)$this->getTeam()?->name),
            'sla' => $this->slaHtml(),
            'satisfaction' => $this->satisfaction !== null
                ? str_repeat('★', $this->satisfaction) . Html::tag('span', str_repeat('★', 5 - $this->satisfaction), ['class' => 'light'])
                : Html::tag('span', '—', ['class' => 'light']),
            'submittedSite' => Html::encode((string)$this->getSubmittedSite()?->name),
            default => parent::attributeHtml($attribute),
        };
    }

    private function requesterHtml(): string
    {
        $label = Html::encode($this->getRequesterLabel());
        $user = $this->getRequester();

        return $user !== null
            ? Html::a($label, (string)$user->getCpEditUrl())
            : Html::a($label, 'mailto:' . $this->requesterEmail);
    }

    private function statusHtml(): string
    {
        $status = $this->getTicketStatus();

        if ($status === null) {
            return Html::tag('span', '—', ['class' => 'light']);
        }

        // Dot and label as siblings — `.status` is a fixed-size circle, and text placed inside it
        // wraps one letter per line.
        return Html::tag('span', '', ['class' => ['status', $status->color]])
            . Html::tag('span', Html::encode($status->name));
    }

    private function slaHtml(): string
    {
        if (!$this->getHasSla()) {
            return Html::tag('span', '—', ['class' => 'light']);
        }

        if ($this->getIsBreaching()) {
            return Html::tag('span', Craft::t('csr', 'Late'), ['class' => 'status-label error']);
        }

        $remaining = $this->getFirstResponseRemaining() ?? $this->getResolutionRemaining();

        if ($remaining === null) {
            return Html::tag('span', Craft::t('csr', 'Met'), ['class' => 'light']);
        }

        $label = SlaPolicy::humanDuration((int)max(1, round($remaining / 60)));

        return Html::tag('span', $label, $this->getIsAtRisk() ? ['class' => 'status-label'] : []);
    }

    // Permissions
    // -------------------------------------------------------------------------

    public function canView(User $user): bool
    {
        return $user->can(Plugin::PERMISSION_VIEW_TICKETS);
    }

    public function canSave(User $user): bool
    {
        return $user->can(Plugin::PERMISSION_MANAGE_TICKETS);
    }

    public function canDuplicate(User $user): bool
    {
        return false;
    }

    public function canDelete(User $user): bool
    {
        return $user->can(Plugin::PERMISSION_DELETE_TICKETS);
    }
}
