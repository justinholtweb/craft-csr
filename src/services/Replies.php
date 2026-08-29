<?php

declare(strict_types=1);

namespace justinholtweb\csr\services;

use Craft;
use craft\base\Component;
use craft\db\Query;
use craft\helpers\ArrayHelper;
use craft\helpers\Db;
use craft\helpers\FileHelper;
use craft\helpers\HtmlPurifier;
use DateInterval;
use DateTime;
use justinholtweb\csr\elements\Ticket;
use justinholtweb\csr\enums\EventType;
use justinholtweb\csr\enums\StatusKind;
use justinholtweb\csr\events\TicketEvent;
use justinholtweb\csr\helpers\Visitor;
use justinholtweb\csr\models\Attachment;
use justinholtweb\csr\models\Reply;
use justinholtweb\csr\Plugin;
use justinholtweb\csr\records\ReplyRecord;
use justinholtweb\csr\records\Table;
use yii\db\Expression;

/**
 * What gets said on a ticket, and everything that follows from saying it.
 *
 * A reply is never just a row. The first one from the desk stops the first-response clock — the
 * number customers actually feel — a reply from the customer takes the ticket out of "waiting on
 * them" and puts the ball back, and a reply on a closed ticket reopens it rather than vanishing
 * into a conversation nobody is watching.
 *
 * Internal notes go in the same table, distinguished by `isNote`, so the timeline is one sequence
 * rather than two that have to be merged and eventually disagree about the order. The safety
 * property runs the other way: a note must never reach the customer, which is enforced by the
 * portal asking for {@see Ticket::getPublicReplies()} rather than by an `if` in a template a site
 * is free to rewrite.
 */
class Replies extends Component
{
    public const EVENT_AFTER_REPLY = 'afterReply';

    /**
     * Post a reply or an internal note.
     *
     * @param array{
     *     body: string, isNote?: bool, byAgent?: bool, userId?: int|null,
     *     authorName?: string|null, authorEmail?: string|null, cannedReplyId?: int|null,
     *     source?: string|null, notify?: bool, attachments?: Attachment[],
     * } $data
     */
    public function post(Ticket $ticket, array $data): ?Reply
    {
        $body = trim((string)($data['body'] ?? ''));

        if ($body === '') {
            return null;
        }

        $isNote = (bool)($data['isNote'] ?? false);
        $byAgent = (bool)($data['byAgent'] ?? false);

        $record = new ReplyRecord();
        $record->ticketId = (int)$ticket->id;
        $record->userId = $data['userId'] ?? Craft::$app->getUser()->getId();
        $record->authorName = $data['authorName'] ?? null;
        $record->authorEmail = $data['authorEmail'] ?? null;
        // Purified rather than trusted: this is rendered as HTML on the ticket screen and in the
        // notification email, and half of what arrives here was typed by a stranger.
        $record->body = HtmlPurifier::process($body, $this->purifierConfig());
        $record->isNote = $isNote;
        $record->byAgent = $byAgent;
        $record->cannedReplyId = $data['cannedReplyId'] ?? null;
        $record->source = $data['source'] ?? null;
        $record->ipAddress = Visitor::ip();

        if (!$record->save(false)) {
            return null;
        }

        $reply = $this->toModel($record);

        foreach ($data['attachments'] ?? [] as $attachment) {
            Db::update(Table::ATTACHMENTS, ['replyId' => $record->id], ['id' => $attachment->id], [], false);
        }

        if ($record->cannedReplyId !== null) {
            Db::update(
                Table::CANNED_REPLIES,
                ['useCount' => new Expression('[[useCount]] + 1')],
                ['id' => $record->cannedReplyId],
                [],
                false,
            );
        }

        $this->applyToTicket($ticket, $reply);

        Plugin::getInstance()->tickets->recordEvent(
            $ticket,
            $isNote ? EventType::Noted : EventType::Replied,
            $isNote ? [] : ['actor' => $byAgent ? null : $ticket->getRequesterLabel()],
        );

        if (!$isNote && ($data['notify'] ?? true)) {
            Plugin::getInstance()->pigeon->mirrorReply($ticket, $reply);
            Plugin::getInstance()->notifications->sendReply($ticket, $reply);
        }

        $this->trigger(self::EVENT_AFTER_REPLY, new TicketEvent(['ticket' => $ticket, 'reply' => $reply]));

        return $reply;
    }

    /**
     * Everything a reply changes about the ticket it is on.
     *
     * An internal note changes nothing here on purpose — writing a note to yourself must not stop
     * the first-response clock, because nobody has responded to anybody.
     */
    private function applyToTicket(Ticket $ticket, Reply $reply): void
    {
        if ($reply->isNote) {
            Craft::$app->getElements()->saveElement($ticket, false, false, false);

            return;
        }

        $now = new DateTime();
        $statuses = Plugin::getInstance()->statuses;
        $slas = Plugin::getInstance()->slas;
        $status = $ticket->getTicketStatus();

        $ticket->replyCount++;
        $ticket->lastReplyAt = $now;
        $ticket->lastReplyBy = $reply->byAgent ? 'agent' : 'requester';

        if ($reply->byAgent) {
            $ticket->agentReplyCount++;

            // The first response, and the only chance to stamp it. Every later reply is just a
            // reply, and overwriting this would erase the number the SLA report is built on.
            if ($ticket->firstRespondedAt === null) {
                $ticket->firstRespondedAt = $now;
            }

            // A new ticket somebody has answered is no longer new.
            if ($status?->getKind() === StatusKind::New) {
                $open = $statuses->getFirstOfKind(StatusKind::Open);

                if ($open !== null) {
                    Plugin::getInstance()->tickets->setStatus($ticket, (int)$open->id, false, false);
                }
            }
        } else {
            // The customer replied, so the desk is no longer waiting on them. Resuming the clock
            // here rather than when somebody notices is the difference between an SLA report that
            // measures the desk and one that measures the customer.
            if ($status?->getKind()->pausesClock()) {
                $slas->resume($ticket);
                $open = $statuses->getFirstOfKind(StatusKind::Open);

                if ($open !== null) {
                    Plugin::getInstance()->tickets->setStatus($ticket, (int)$open->id, false, false);
                }
            } elseif ($status !== null && !$status->isOpen()) {
                $this->reopen($ticket);
            }
        }

        Craft::$app->getElements()->saveElement($ticket, false, false, false);
    }

    /**
     * A reply on a finished ticket brings it back, if it is recent enough.
     *
     * Past the window a new ticket is the right answer: a customer replying to a nine-month-old
     * thread is asking a new question, and reopening the old one buries it under history nobody
     * involved remembers. Under the window, reopening keeps the context that makes the reply make
     * sense.
     */
    private function reopen(Ticket $ticket): void
    {
        $days = Plugin::getInstance()->getSettings()->reopenWithinDays;
        $closedAt = $ticket->closedAt ?? $ticket->resolvedAt;

        if ($days > 0 && $closedAt !== null) {
            $deadline = (clone $closedAt)->add(new DateInterval('P' . $days . 'D'));

            if (new DateTime() > $deadline) {
                return;
            }
        }

        $open = Plugin::getInstance()->statuses->getFirstOfKind(StatusKind::Open);

        if ($open !== null) {
            Plugin::getInstance()->tickets->setStatus($ticket, (int)$open->id, false, false);
        }
    }

    /**
     * @return Reply[] Oldest first, with their attachments attached in one extra query rather
     *                 than one per reply.
     */
    public function getReplies(int $ticketId, bool $includeNotes = true): array
    {
        $query = (new Query())
            ->select([
                'id', 'ticketId', 'userId', 'authorName', 'authorEmail', 'body',
                'isNote', 'byAgent', 'cannedReplyId', 'source', 'dateCreated',
            ])
            ->from([Table::REPLIES])
            ->where(['ticketId' => $ticketId])
            ->orderBy(['dateCreated' => SORT_ASC, 'id' => SORT_ASC]);

        if (!$includeNotes) {
            $query->andWhere(['isNote' => false]);
        }

        $replies = array_map(static fn(array $row) => new Reply($row), $query->all());

        if ($replies === []) {
            return [];
        }

        $attachments = ArrayHelper::index(
            Plugin::getInstance()->tickets->getAttachments($ticketId),
            null,
            [static fn(Attachment $attachment) => (string)($attachment->replyId ?? 0)],
        );

        foreach ($replies as $reply) {
            $reply->setAttachments($attachments[(string)$reply->id] ?? []);
        }

        return $replies;
    }

    public function getReplyById(int $id): ?Reply
    {
        $row = (new Query())
            ->select([
                'id', 'ticketId', 'userId', 'authorName', 'authorEmail', 'body',
                'isNote', 'byAgent', 'cannedReplyId', 'source', 'dateCreated',
            ])
            ->from([Table::REPLIES])
            ->where(['id' => $id])
            ->one();

        return $row !== false && $row !== null ? new Reply($row) : null;
    }

    public function deleteReplyById(int $id): bool
    {
        $record = ReplyRecord::findOne($id);

        if ($record === null) {
            return false;
        }

        $ticketId = (int)$record->ticketId;
        $deleted = (bool)$record->delete();

        if ($deleted) {
            Plugin::getInstance()->tickets->recountReplies($ticketId);
        }

        return $deleted;
    }

    private function toModel(ReplyRecord $record): Reply
    {
        return new Reply([
            'id' => (int)$record->id,
            'ticketId' => (int)$record->ticketId,
            'userId' => $record->userId !== null ? (int)$record->userId : null,
            'authorName' => $record->authorName,
            'authorEmail' => $record->authorEmail,
            'body' => $record->body,
            'isNote' => (bool)$record->isNote,
            'byAgent' => (bool)$record->byAgent,
            'cannedReplyId' => $record->cannedReplyId !== null ? (int)$record->cannedReplyId : null,
            'source' => $record->source,
            'dateCreated' => new DateTime(),
        ]);
    }

    /**
     * What a reply is allowed to contain.
     *
     * Deliberately narrow. A support reply is prose with the occasional link, list or bit of code
     * in it; anything that can carry a script, an iframe or a style is not something a stranger
     * gets to put into a page an agent will open.
     *
     * @return array<string, mixed>
     */
    private function purifierConfig(): array
    {
        $cachePath = Craft::$app->getPath()->getRuntimePath() . DIRECTORY_SEPARATOR . 'csr-purifier';
        FileHelper::createDirectory($cachePath);

        return [
            'HTML.Allowed' => 'p,br,strong,b,em,i,u,a[href|title|rel],ul,ol,li,blockquote,code,pre,h3,h4',
            'HTML.TargetBlank' => true,
            'Attr.AllowedFrameTargets' => ['_blank'],
            'URI.AllowedSchemes' => ['http' => true, 'https' => true, 'mailto' => true],
            // HTMLPurifier writes a definition cache, and a directory it cannot write to is a
            // fatal rather than a slow path.
            'Cache.SerializerPath' => $cachePath,
        ];
    }
}
