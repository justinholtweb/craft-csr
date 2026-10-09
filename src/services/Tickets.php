<?php

declare(strict_types=1);

namespace justinholtweb\csr\services;

use Craft;
use craft\base\Component;
use craft\db\Query;
use craft\elements\Asset;
use craft\elements\User;
use craft\helpers\Db;
use craft\helpers\Json;
use craft\helpers\Queue;
use craft\helpers\StringHelper;
use DateTime;
use justinholtweb\csr\elements\Ticket;
use justinholtweb\csr\enums\EventType;
use justinholtweb\csr\enums\Priority;
use justinholtweb\csr\enums\StatusKind;
use justinholtweb\csr\enums\TicketSource;
use justinholtweb\csr\events\TicketEvent as TicketElementEvent;
use justinholtweb\csr\helpers\Visitor;
use justinholtweb\csr\jobs\SendSatisfactionRequest;
use justinholtweb\csr\models\Attachment;
use justinholtweb\csr\models\Edition;
use justinholtweb\csr\models\Settings;
use justinholtweb\csr\models\Status;
use justinholtweb\csr\models\TicketEvent as TicketEventModel;
use justinholtweb\csr\Plugin;
use justinholtweb\csr\records\AttachmentRecord;
use justinholtweb\csr\records\EventRecord;
use justinholtweb\csr\records\Table;
use Throwable;

/**
 * The desk.
 *
 * ## Where the trust boundary is
 *
 * {@see self::submit()} and nothing else. Everything in it assumes the request is hostile: the
 * category is looked up and then re-asked whether it accepts tickets, the custom questions come
 * from the *form* rather than from the request (so a value posted under an unknown handle is never
 * looked at), the priority is only honoured if the site said visitors may choose one, the status
 * is the desk's default and never the request's, and every spam refusal returns the same message
 * so a robot cannot learn which check caught it.
 *
 * ## Numbering
 *
 * Element IDs are shared with every other element in the install, so two tickets an hour apart can
 * be four hundred apart, and a customer quoting "ticket 4,182,001" is quoting something that looks
 * invented. `number` is allocated from the table's own maximum inside the insert's transaction.
 */
class Tickets extends Component
{
    /** Where the Ticket element's own field layout lives in project config. */
    public const CONFIG_FIELD_LAYOUT_KEY = 'csr.ticketFieldLayout';

    /** Fired after a ticket has been raised and everything derived from it has been written. */
    public const EVENT_AFTER_SUBMIT = 'afterSubmit';

    /** Fired after a ticket's status has changed, whoever changed it. */
    public const EVENT_AFTER_STATUS_CHANGE = 'afterStatusChange';

    // Submitting
    // -------------------------------------------------------------------------

    /**
     * Raise a ticket from an untrusted request.
     *
     * @param array{
     *     name?: string|null, email?: string, subject?: string, message?: string,
     *     categoryId?: int|string|null, priority?: string|null, answers?: array<string, mixed>,
     *     siteId?: int|null, source?: TicketSource, requesterId?: int|null,
     * } $data
     */
    public function submit(array $data, bool $runValidation = true): Ticket
    {
        $settings = Plugin::getInstance()->getSettings();
        $plugin = Plugin::getInstance();

        $ticket = new Ticket();
        $ticket->source = ($data['source'] ?? TicketSource::Web)->value;
        $ticket->submittedSiteId = (int)($data['siteId'] ?? Craft::$app->getSites()->getCurrentSite()->id);
        $ticket->submittedAt = new DateTime();
        $ticket->ipAddress = Visitor::ip();
        $ticket->userAgent = Visitor::userAgent();

        // Who is asking. A logged-in requester's own account details win over anything posted:
        // somebody signed in as one person may not raise a ticket as another.
        // Who is asking, and it is worth being exact about the three cases.
        //
        // `requesterId` given: that account, whatever the request said — the control panel raising
        // a ticket on somebody's behalf, and an integration.
        // `requesterId` given as null: nobody has an account. **Not** the same as absent, and the
        // reason this is `array_key_exists` rather than `isset`: an agent typing a guest's address
        // into the control panel form must not have the ticket filed under their own name.
        // Absent: the visitor, if they are signed in. A signed-in person may not raise a ticket
        // as somebody else.
        if (array_key_exists('requesterId', $data)) {
            $user = $data['requesterId'] !== null
                ? Craft::$app->getUsers()->getUserById((int)$data['requesterId'])
                : null;
        } else {
            $user = Craft::$app->getUser()->getIdentity();
        }

        if ($user instanceof User) {
            $ticket->requesterId = (int)$user->id;
            $ticket->requesterEmail = (string)$user->email;
            $ticket->requesterName = $user->getFriendlyName() ?: null;
        } else {
            $ticket->requesterEmail = trim((string)($data['email'] ?? ''));
            $ticket->requesterName = ($name = trim((string)($data['name'] ?? ''))) !== '' ? $name : null;
        }

        $ticket->title = StringHelper::safeTruncate(trim((string)($data['subject'] ?? '')), 255);
        $ticket->message = trim((string)($data['message'] ?? '')) ?: null;

        // The subject is what everything downstream identifies the ticket by, and somebody who
        // typed only a message should not end up with a blank one.
        if ($ticket->title === '' && $ticket->message !== null) {
            $ticket->title = StringHelper::safeTruncate($ticket->message, 80);
        }

        $this->applyCategory($ticket, $data['categoryId'] ?? null);
        $this->applyTeam($ticket);
        $this->applyPriority($ticket, $data['priority'] ?? null, $settings);

        // The answers come from the *form*: the fields are enumerated from configuration and each
        // one pulls its own value out of the request. Nothing posted under a handle the form does
        // not define is ever read.
        $ticket->setAnswers($plugin->forms->collect($data['answers'] ?? [], $ticket->getCategory()));

        $ticket->statusId = $plugin->statuses->getDefaultStatus()?->id;

        $plugin->slas->apply($ticket, false);
        $this->autoAssign($ticket, $settings);

        if (!Craft::$app->getElements()->saveElement($ticket, $runValidation)) {
            return $ticket;
        }

        $this->afterSubmit($ticket);

        return $ticket;
    }

    /** Everything that has to happen once a ticket exists and has an ID. */
    private function afterSubmit(Ticket $ticket): void
    {
        $plugin = Plugin::getInstance();

        $this->recordEvent($ticket, EventType::Created, [
            'source' => $ticket->source,
            'actor' => $ticket->getRequesterLabel(),
        ]);

        if ($ticket->slaPolicyId !== null) {
            $this->recordEvent($ticket, EventType::SlaApplied, [
                'policy' => (string)$ticket->getSlaPolicy()?->name,
            ]);
        }

        // Attach the deflection session, so "did this person read the suggestions and write in
        // anyway" is answerable — which is the other half of the deflection rate.
        $plugin->deflection->recordSubmitted($ticket);

        if ($ticket->deflectionId !== null) {
            Db::update(Table::TICKETS, ['deflectionId' => $ticket->deflectionId], ['id' => $ticket->id], [], false);
        }

        $plugin->pigeon->openThread($ticket);
        $plugin->notifications->sendTicketRaised($ticket);

        $this->trigger(self::EVENT_AFTER_SUBMIT, new TicketElementEvent(['ticket' => $ticket]));
    }

    /** A category the request named, only if it exists, is enabled, and takes tickets. */
    private function applyCategory(Ticket $ticket, mixed $categoryId): void
    {
        if ($categoryId === null || $categoryId === '') {
            return;
        }

        $category = Plugin::getInstance()->categories->getCategoryById((int)$categoryId);

        if ($category !== null && $category->enabled && $category->acceptsTickets) {
            $ticket->categoryId = (int)$category->id;
        }
    }

    /**
     * The team the ticket's category routes to — before the SLA policy and the auto-assignment,
     * both of which can depend on it.
     */
    private function applyTeam(Ticket $ticket): void
    {
        if ($ticket->teamId !== null || !Edition::allowsTeams(Plugin::getInstance()->isPro())) {
            return;
        }

        $teamId = $ticket->getCategory()?->getRoutedTeamId();

        if ($teamId !== null && Plugin::getInstance()->teams->getTeamById($teamId) !== null) {
            $ticket->teamId = $teamId;
        }
    }

    /**
     * The priority, but only if the site said visitors may pick one.
     *
     * Otherwise everything arrives urgent, and a queue in which everything is urgent is a queue
     * sorted by nothing.
     */
    private function applyPriority(Ticket $ticket, mixed $priority, Settings $settings): void
    {
        $ticket->priority = $settings->getDefaultPriority()->value;

        if ($settings->allowPrioritySelection && is_string($priority)) {
            $chosen = Priority::tryFrom($priority);

            if ($chosen !== null) {
                $ticket->priority = $chosen->value;
            }
        }
    }

    // Assignment
    // -------------------------------------------------------------------------

    /**
     * Give the ticket to somebody, if the site asked for that.
     *
     * Round-robin and least-busy are both Pro, because both only mean anything once there is more
     * than one agent. Neither ever assigns to somebody with no permission to see tickets.
     */
    public function autoAssign(Ticket $ticket, ?Settings $settings = null): void
    {
        $settings ??= Plugin::getInstance()->getSettings();

        if (
            $settings->autoAssign === Settings::ASSIGN_NONE
            || $ticket->agentId !== null
            || !Edition::allowsTeams(Plugin::getInstance()->isPro())
        ) {
            return;
        }

        $candidates = $this->getAssignableAgents($ticket);

        if ($candidates === []) {
            return;
        }

        $ticket->agentId = match ($settings->autoAssign) {
            Settings::ASSIGN_LEAST_BUSY => $this->leastBusy($candidates),
            default => $this->nextInRotation($candidates),
        };
    }

    /**
     * Who could take this ticket.
     *
     * The team's members when it is routed to one, and everybody with the manage permission
     * otherwise. Suspended and pending accounts are excluded — assigning a ticket to somebody who
     * cannot log in is how tickets go missing.
     *
     * @return list<int>
     */
    public function getAssignableAgents(?Ticket $ticket = null): array
    {
        $team = $ticket?->getTeam();

        if ($team !== null) {
            $ids = Plugin::getInstance()->teams->getMemberIds((int)$team->id);
        } else {
            $ids = array_map(
                static fn(User $user) => (int)$user->id,
                User::find()->status(User::STATUS_ACTIVE)->limit(null)->all(),
            );
        }

        return array_values(array_filter($ids, static function(int $id): bool {
            $user = Craft::$app->getUsers()->getUserById($id);

            return $user !== null && $user->can(Plugin::PERMISSION_MANAGE_TICKETS);
        }));
    }

    /** @param non-empty-list<int> $candidates */
    private function nextInRotation(array $candidates): int
    {
        // Whoever was given a ticket longest ago. A stored pointer would drift the moment somebody
        // joined or left; the tickets themselves already record the rotation.
        $last = (new Query())
            ->select(['agentId', 'last' => 'MAX([[submittedAt]])'])
            ->from([Table::TICKETS])
            ->where(['agentId' => $candidates])
            ->groupBy(['agentId'])
            ->pairs();

        $best = null;
        $bestTime = null;

        foreach ($candidates as $id) {
            $time = $last[$id] ?? null;

            if ($time === null) {
                return $id;
            }

            if ($bestTime === null || $time < $bestTime) {
                $best = $id;
                $bestTime = $time;
            }
        }

        return $best;
    }

    /** @param int[] $candidates */
    private function leastBusy(array $candidates): int
    {
        $openStatusIds = array_map(
            static fn(Status $status) => (int)$status->id,
            array_filter(Plugin::getInstance()->statuses->getAllStatuses(), static fn(Status $s) => $s->isOpen()),
        );

        $counts = (new Query())
            ->select(['agentId', 'total' => 'COUNT(*)'])
            ->from([Table::TICKETS])
            ->where(['agentId' => $candidates, 'isSpam' => false])
            ->andFilterWhere(['statusId' => $openStatusIds])
            ->groupBy(['agentId'])
            ->pairs();

        $best = $candidates[0];
        $bestCount = PHP_INT_MAX;

        foreach ($candidates as $id) {
            $count = (int)($counts[$id] ?? 0);

            if ($count < $bestCount) {
                $best = $id;
                $bestCount = $count;
            }
        }

        return $best;
    }

    public function assign(Ticket $ticket, ?int $agentId, bool $save = true): bool
    {
        if ($ticket->agentId === $agentId) {
            return true;
        }

        $ticket->agentId = $agentId;

        if ($agentId !== null) {
            $this->recordEvent($ticket, EventType::Assigned, [
                'agent' => (string)Craft::$app->getUsers()->getUserById($agentId)?->getFriendlyName(),
            ]);
        } else {
            $this->recordEvent($ticket, EventType::Unassigned);
        }

        $result = !$save || Craft::$app->getElements()->saveElement($ticket, false);

        if ($result && $agentId !== null) {
            Plugin::getInstance()->notifications->sendAssignment($ticket);
        }

        return $result;
    }

    public function setTeam(Ticket $ticket, ?int $teamId, bool $save = true): bool
    {
        if ($ticket->teamId === $teamId) {
            return true;
        }

        $ticket->teamId = $teamId;

        $this->recordEvent($ticket, EventType::TeamChanged, [
            'team' => (string)Plugin::getInstance()->teams->getTeamById($teamId)?->name,
        ]);

        return !$save || Craft::$app->getElements()->saveElement($ticket, false);
    }

    // Moving it along
    // -------------------------------------------------------------------------

    /**
     * Move a ticket to a status, doing everything that implies.
     *
     * The implications are the reason this is not a property assignment: the SLA clock pauses when
     * a ticket starts waiting on the customer and resumes when it stops, resolution and closure
     * are stamped so the reports can measure them, and a status that emails the requester emails
     * the requester.
     */
    public function setStatus(Ticket $ticket, ?int $statusId, bool $save = true, bool $notify = true): bool
    {
        $from = $ticket->getTicketStatus();
        $to = Plugin::getInstance()->statuses->getStatusById($statusId);

        if ($to === null || $from?->id === $to->id) {
            return true;
        }

        $wasOpen = $from?->isOpen() ?? true;
        $wasResolved = $ticket->resolvedAt !== null;
        $slas = Plugin::getInstance()->slas;

        // The clock. Pausing on the way into "waiting on the customer" and resuming on the way out
        // is what stops every policy being breached by people who reply slowly to their own
        // support requests.
        if ($to->getKind()->pausesClock()) {
            $slas->pause($ticket);
        } elseif ($from?->getKind()->pausesClock()) {
            $slas->resume($ticket);
        }

        $now = new DateTime();

        if ($to->isResolved() && $ticket->resolvedAt === null) {
            $ticket->resolvedAt = $now;
        }

        if ($to->isClosed()) {
            $ticket->closedAt ??= $now;
            $ticket->resolvedAt ??= $now;
        }

        if ($to->isOpen() && !$wasOpen) {
            // Reopened. The stamps go, because a ticket that is open again is not resolved, and a
            // resolution date in the past on an open ticket makes every report wrong.
            $ticket->resolvedAt = null;
            $ticket->closedAt = null;
            $this->recordEvent($ticket, EventType::Reopened);
        }

        $ticket->statusId = (int)$to->id;

        $this->recordEvent($ticket, EventType::StatusChanged, [
            'from' => $from->name ?? Craft::t('csr', 'nothing'),
            'to' => $to->name,
        ]);

        if ($save && !Craft::$app->getElements()->saveElement($ticket, false)) {
            return false;
        }

        if ($notify && $to->notifyRequester) {
            Plugin::getInstance()->notifications->sendStatusChange($ticket, $to);
        }

        if (!$wasResolved && $ticket->resolvedAt !== null) {
            $this->scheduleSatisfactionRequest($ticket);
        }

        $this->trigger(self::EVENT_AFTER_STATUS_CHANGE, new TicketElementEvent([
            'ticket' => $ticket,
            'previousStatus' => $from,
        ]));

        return true;
    }

    /**
     * Queue "how did we do?" for after the configured delay — asking the moment a ticket is
     * resolved is nagging, and asking before anybody has checked the fix worked is asking the
     * wrong question. The job re-checks everything when it runs.
     */
    private function scheduleSatisfactionRequest(Ticket $ticket): void
    {
        $plugin = Plugin::getInstance();
        $settings = $plugin->getSettings();

        if (
            $ticket->id === null
            || $ticket->resolvedAt === null
            || !$settings->satisfactionEnabled
            || !Edition::allowsSatisfactionSurvey($plugin->isPro())
        ) {
            return;
        }

        Queue::push(new SendSatisfactionRequest([
            'ticketId' => (int)$ticket->getCanonicalId(),
            'resolvedAt' => $ticket->resolvedAt->getTimestamp(),
        ]), null, max(0, $settings->satisfactionDelayHours) * 3600);
    }

    public function setPriority(Ticket $ticket, string $priority, bool $save = true): bool
    {
        $new = Priority::tryFrom($priority);

        if ($new === null || $new->value === $ticket->priority) {
            return true;
        }

        $from = $ticket->getPriority();
        $ticket->priority = $new->value;

        $this->recordEvent($ticket, EventType::PriorityChanged, [
            'from' => $from->label(),
            'to' => $new->label(),
        ]);

        // Priority is what most SLA policies are written against, so changing it may hand the
        // ticket to a different policy — and a promise that does not move when the urgency does is
        // not a promise anybody is keeping.
        Plugin::getInstance()->slas->apply($ticket);

        return !$save || Craft::$app->getElements()->saveElement($ticket, false);
    }

    public function setCategory(Ticket $ticket, ?int $categoryId, bool $save = true): bool
    {
        if ($ticket->categoryId === $categoryId) {
            return true;
        }

        $ticket->categoryId = $categoryId;

        $this->recordEvent($ticket, EventType::CategoryChanged, [
            'category' => (string)Plugin::getInstance()->categories->getCategoryById($categoryId)?->name,
        ]);

        Plugin::getInstance()->slas->apply($ticket);

        return !$save || Craft::$app->getElements()->saveElement($ticket, false);
    }

    /**
     * Fold one ticket into another.
     *
     * The duplicate is *not* deleted: its replies are moved across, it is closed, and it keeps a
     * pointer to where it went, so a customer who quotes the number they were given still lands
     * somewhere that makes sense.
     */
    public function merge(Ticket $source, Ticket $target): bool
    {
        if (!Edition::allowsMerge(Plugin::getInstance()->isPro()) || $source->id === $target->id) {
            return false;
        }

        $transaction = Craft::$app->getDb()->beginTransaction();

        try {
            Db::update(Table::REPLIES, ['ticketId' => $target->id], ['ticketId' => $source->id], [], false);
            Db::update(Table::ATTACHMENTS, ['ticketId' => $target->id], ['ticketId' => $source->id], [], false);

            $counts = (new Query())
                ->select([
                    'total' => 'COUNT(*)',
                    'agent' => 'SUM(CASE WHEN [[byAgent]] = 1 AND [[isNote]] = 0 THEN 1 ELSE 0 END)',
                ])
                ->from([Table::REPLIES])
                ->where(['ticketId' => $target->id, 'isNote' => false])
                ->one() ?: [];

            $target->replyCount = (int)($counts['total'] ?? 0);
            $target->agentReplyCount = (int)($counts['agent'] ?? 0);

            $source->mergedIntoId = (int)$target->id;
            $this->recordEvent($source, EventType::Merged, ['number' => $target->number]);
            $this->recordEvent($target, EventType::Merged, ['number' => $source->number]);

            $closed = Plugin::getInstance()->statuses->getFirstOfKind(StatusKind::Closed);
            $this->setStatus($source, $closed?->id, false, false);

            Craft::$app->getElements()->saveElement($source, false);
            Craft::$app->getElements()->saveElement($target, false);

            $transaction->commit();
        } catch (Throwable $exception) {
            $transaction->rollBack();
            Craft::error('Could not merge CSR tickets: ' . $exception->getMessage(), 'csr');

            return false;
        }

        return true;
    }

    /** The customer's verdict, once the thing is finished. */
    public function rate(Ticket $ticket, int $score, ?string $comment = null): bool
    {
        if (!Edition::allowsSatisfactionSurvey(Plugin::getInstance()->isPro())) {
            return false;
        }

        $score = max(1, min(5, $score));
        $ticket->satisfaction = $score;

        if ($comment !== null && trim($comment) !== '') {
            $ticket->satisfactionComment = mb_substr(trim($comment), 0, 2000);
        }

        $this->recordEvent($ticket, EventType::Rated, ['score' => $score]);

        return Craft::$app->getElements()->saveElement($ticket, false);
    }

    public function markAsSpam(Ticket $ticket, bool $isSpam = true): bool
    {
        $ticket->isSpam = $isSpam;

        return Craft::$app->getElements()->saveElement($ticket, false);
    }

    // Numbering
    // -------------------------------------------------------------------------

    /**
     * The next ticket number.
     *
     * Read from the table's own maximum rather than from a counter row, because a counter is one
     * more thing to get out of step with reality after a restore, an import or a manual delete —
     * and the answer to "what is the highest number so far" is always available for free.
     */
    public function allocateNumber(): int
    {
        $start = max(1, Plugin::getInstance()->getSettings()->ticketNumberStart);
        $max = (new Query())->from([Table::TICKETS])->max('[[number]]');

        return max($start, (int)$max + 1);
    }

    public function getTicketByNumber(int $number): ?Ticket
    {
        /** @var Ticket|null $ticket */
        $ticket = Ticket::find()->number($number)->isSpam(null)->one();

        return $ticket;
    }

    /**
     * The ticket this reference belongs to.
     *
     * The only way a requester without an account reaches their own ticket. Compared in constant
     * time because it is a secret compared against user input, and looked up on an indexed column
     * so that a wrong reference costs the same as a right one.
     */
    public function getTicketByReference(string $reference): ?Ticket
    {
        if (strlen($reference) !== 32) {
            return null;
        }

        /** @var Ticket|null $ticket */
        $ticket = Ticket::find()->reference($reference)->isSpam(null)->one();

        return $ticket !== null && hash_equals((string)$ticket->reference, $reference) ? $ticket : null;
    }

    // The timeline
    // -------------------------------------------------------------------------

    /** @param array<string, mixed> $data */
    public function recordEvent(Ticket $ticket, EventType $type, array $data = []): void
    {
        if ($ticket->id === null) {
            return;
        }

        $record = new EventRecord();
        $record->ticketId = (int)$ticket->id;
        $record->type = $type->value;
        $record->userId = Craft::$app->getUser()->getId();
        $record->data = $data === [] ? null : Json::encode($data);
        $record->save(false);
    }

    /** @return TicketEventModel[] Oldest first — a timeline reads forwards. */
    public function getEvents(int $ticketId): array
    {
        $rows = (new Query())
            ->select(['id', 'ticketId', 'type', 'userId', 'data', 'dateCreated'])
            ->from([Table::EVENTS])
            ->where(['ticketId' => $ticketId])
            ->orderBy(['dateCreated' => SORT_ASC, 'id' => SORT_ASC])
            ->all();

        return array_map(static fn(array $row) => new TicketEventModel($row), $rows);
    }

    // Attachments
    // -------------------------------------------------------------------------

    /** @return Attachment[] */
    public function getAttachments(int $ticketId, ?int $replyId = null): array
    {
        $query = (new Query())
            ->select(['id', 'ticketId', 'replyId', 'assetId', 'filename', 'size', 'kind', 'uploadedBy', 'dateCreated'])
            ->from([Table::ATTACHMENTS])
            ->where(['ticketId' => $ticketId])
            ->orderBy(['id' => SORT_ASC]);

        if ($replyId !== null) {
            $query->andWhere(['replyId' => $replyId]);
        }

        return array_map(static fn(array $row) => new Attachment($row), $query->all());
    }

    public function getAttachmentById(int $id): ?Attachment
    {
        $row = (new Query())
            ->select(['id', 'ticketId', 'replyId', 'assetId', 'filename', 'size', 'kind', 'uploadedBy', 'dateCreated'])
            ->from([Table::ATTACHMENTS])
            ->where(['id' => $id])
            ->one();

        return $row !== false && $row !== null ? new Attachment($row) : null;
    }

    /** Record that an asset belongs to a ticket. The upload itself is the controller's job. */
    public function attach(Ticket $ticket, Asset $asset, ?int $replyId = null): Attachment
    {
        $record = new AttachmentRecord();
        $record->ticketId = (int)$ticket->id;
        $record->replyId = $replyId;
        $record->assetId = (int)$asset->id;
        $record->filename = (string)$asset->getFilename();
        $record->size = (int)$asset->size;
        $record->kind = $asset->kind;
        $record->uploadedBy = Craft::$app->getUser()->getId();
        $record->save(false);

        return new Attachment([
            'id' => (int)$record->id,
            'ticketId' => (int)$record->ticketId,
            'replyId' => $record->replyId !== null ? (int)$record->replyId : null,
            'assetId' => (int)$record->assetId,
            'filename' => $record->filename,
            'size' => (int)$record->size,
            'kind' => $record->kind,
        ]);
    }

    /**
     * Store a file somebody sent as an asset in the attachments volume, and attach it.
     *
     * The one way a file reaches a ticket — the support form and inbound email both come through
     * here, so the volume, the per-ticket folder and the filename rules are the same whichever door
     * it came in by. Checking *whether* a file is acceptable is the caller's job, because each door
     * knows different things about it. A failure is logged and returns null: a ticket must never be
     * lost because its screenshot could not be kept.
     */
    public function storeAttachment(Ticket $ticket, string $tempPath, string $filename, ?int $replyId = null): ?Attachment
    {
        $settings = Plugin::getInstance()->getSettings();

        if (!$settings->attachmentsEnabled || $settings->attachmentVolumeUid === null || $ticket->id === null) {
            return null;
        }

        $volume = Craft::$app->getVolumes()->getVolumeByUid($settings->attachmentVolumeUid);

        if ($volume === null) {
            return null;
        }

        try {
            $folder = Craft::$app->getAssets()->ensureFolderByFullPathAndVolume(
                $settings->attachmentSubpath . '/' . $ticket->number,
                $volume,
            );

            $asset = new Asset();
            $asset->tempFilePath = $tempPath;
            $asset->setFilename(\craft\helpers\Assets::prepareAssetName($filename));
            $asset->newFolderId = $folder->id;
            $asset->setVolumeId((int)$volume->id);
            $asset->avoidFilenameConflicts = true;
            $asset->setScenario(Asset::SCENARIO_CREATE);

            if (Craft::$app->getElements()->saveElement($asset)) {
                return $this->attach($ticket, $asset, $replyId);
            }

            Craft::warning(
                'CSR could not store an attachment on ticket #' . $ticket->number . ': ' . implode(' ', $asset->getFirstErrors()),
                Plugin::LOG_CATEGORY,
            );
        } catch (Throwable $exception) {
            Craft::warning(
                'CSR could not store an attachment on ticket #' . $ticket->number . ': ' . $exception->getMessage(),
                Plugin::LOG_CATEGORY,
            );
        }

        return null;
    }

    // Housekeeping
    // -------------------------------------------------------------------------

    /**
     * Close tickets that were resolved a while ago and nobody argued with.
     *
     * The distinction between resolved and closed is worth keeping: resolved is a claim the desk
     * makes, closed is what happens when the customer does not come back. Collapsing them would
     * lose the window in which somebody can say "actually, that did not fix it" and have the
     * conversation continue rather than start again.
     *
     * @return int How many were closed.
     */
    public function closeResolved(?int $days = null): int
    {
        $days ??= Plugin::getInstance()->getSettings()->closeResolvedAfterDays;

        if ($days <= 0) {
            return 0;
        }

        $closed = Plugin::getInstance()->statuses->getFirstOfKind(StatusKind::Closed);

        if ($closed === null) {
            return 0;
        }

        $cutoff = (new DateTime())->modify("-{$days} days");
        $resolvedIds = array_map(
            static fn(Status $status) => (int)$status->id,
            Plugin::getInstance()->statuses->getStatusesByKind(StatusKind::Resolved),
        );

        if ($resolvedIds === []) {
            return 0;
        }

        /** @var Ticket[] $tickets */
        $tickets = Ticket::find()
            ->statusId($resolvedIds)
            ->limit(null)
            ->all();

        $count = 0;

        foreach ($tickets as $ticket) {
            if ($ticket->resolvedAt !== null && $ticket->resolvedAt < $cutoff) {
                $this->setStatus($ticket, (int)$closed->id, true, false);
                $count++;
            }
        }

        return $count;
    }

    /** Delete tickets past the retention period. Zero days keeps them forever. */
    public function prune(?int $days = null): int
    {
        $days ??= Plugin::getInstance()->getSettings()->ticketRetentionDays;

        if ($days <= 0) {
            return 0;
        }

        $cutoff = (new DateTime())->modify("-{$days} days");

        /** @var Ticket[] $tickets */
        $tickets = Ticket::find()
            ->isSpam(null)
            ->submittedBefore($cutoff)
            ->statusKind([StatusKind::Resolved, StatusKind::Closed])
            ->limit(500)
            ->all();

        $count = 0;

        foreach ($tickets as $ticket) {
            // A hard delete: retention exists because somebody promised the data would be gone,
            // and a ticket sitting in the trash is not gone.
            if (Craft::$app->getElements()->deleteElement($ticket, true)) {
                $count++;
            }
        }

        return $count;
    }

    /** @return array{open: int, unassigned: int, awaitingFirstResponse: int, breaching: int} */
    public function getQueueCounts(?int $agentId = null): array
    {
        $base = static fn() => Ticket::find()->openOnly();

        return [
            'open' => (int)($agentId !== null ? $base()->agentId($agentId)->count() : $base()->count()),
            'unassigned' => (int)$base()->agentId(false)->count(),
            'awaitingFirstResponse' => (int)$base()->awaitingFirstResponse()->count(),
            'breaching' => (int)$base()->breaching()->count(),
        ];
    }

    /**
     * Apply a change to the ticket field layout arriving through project config.
     *
     * The layout the *desk* fills in after a ticket arrives — an account number, an internal
     * reference — as opposed to the questions the customer answered, which are the support form's
     * and live in `csr_formfields`.
     */
    public function handleChangedFieldLayout(\craft\events\ConfigEvent $event): void
    {
        $data = $event->newValue;
        $fieldsService = Craft::$app->getFields();

        if (empty($data) || empty($config = reset($data))) {
            $fieldsService->deleteLayoutsByType(Ticket::class);

            return;
        }

        $uid = key($data);
        $layout = \craft\models\FieldLayout::createFromConfig($config);
        $layout->id = $fieldsService->getLayoutByType(Ticket::class)->id;
        $layout->type = Ticket::class;
        $layout->uid = $uid;

        $fieldsService->saveLayout($layout, false);
    }

    /** Keep the denormalised reply counters honest. Used by the console command and the suite. */
    public function recountReplies(int $ticketId): void
    {
        $counts = (new Query())
            ->select([
                'total' => 'COUNT(*)',
                'agent' => 'SUM(CASE WHEN [[byAgent]] = 1 THEN 1 ELSE 0 END)',
            ])
            ->from([Table::REPLIES])
            ->where(['ticketId' => $ticketId, 'isNote' => false])
            ->one() ?: [];

        Db::update(Table::TICKETS, [
            'replyCount' => (int)($counts['total'] ?? 0),
            'agentReplyCount' => (int)($counts['agent'] ?? 0),
        ], ['id' => $ticketId], [], false);
    }
}
