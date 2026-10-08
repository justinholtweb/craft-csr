<?php

declare(strict_types=1);

namespace justinholtweb\csr\services;

use Craft;
use craft\base\Component;
use craft\helpers\Db;
use justinholtweb\csr\elements\Ticket;
use justinholtweb\csr\enums\TicketSource;
use justinholtweb\csr\models\Reply;
use justinholtweb\csr\Plugin;
use justinholtweb\csr\records\Table;
use Throwable;
use yii\base\Event;

/**
 * The bridge to Pigeon, when it is installed.
 *
 * CSR and Pigeon overlap on exactly one thing — a threaded back-and-forth with somebody who may
 * not have an account — and Pigeon does that better, with read receipts, guest tokens, an inbox
 * and a control panel widget built for reading conversations. Where both are installed and the
 * bridge is switched on, a ticket opens a Pigeon thread and replies flow both ways, leaving CSR to
 * do what it is actually for: the article that should have prevented the ticket, and the clock on
 * the promise to answer it.
 *
 * ## Everything here is optional, and defensive
 *
 * CSR does not require Pigeon, does not import a single one of its classes at the top of a file,
 * and never fails a ticket because the bridge did not work. Pigeon is reached entirely through
 * `Craft::$app->getPlugins()` and duck typing: a version whose method signatures have moved makes
 * the bridge a no-op with a line in the log, rather than making the support form throw.
 *
 * That is a deliberate trade. A customer trying to report an outage must never meet a stack trace
 * because two plugins disagreed about an argument.
 */
class Pigeon extends Component
{
    private const HANDLE = 'pigeon';

    /** Pigeon's message service, named as a string so nothing here autoloads it. */
    private const MESSAGES_CLASS = 'justinholtweb\\pigeon\\services\\Messages';

    private ?bool $_available = null;

    /** Set while CSR writes into Pigeon, so the write is not brought back as a reply. */
    private bool $_mirroring = false;

    /** Set while a Pigeon message is being written into CSR, so it is not sent back. */
    private bool $_bringingAcross = false;

    /** Whether Pigeon is installed, enabled, and the bridge has been switched on. */
    public function isEnabled(): bool
    {
        return Plugin::getInstance()->getSettings()->pigeonBridge && $this->isInstalled();
    }

    /** Whether Pigeon is here at all, regardless of the setting. Used by the settings screen. */
    public function isInstalled(): bool
    {
        return $this->_available ??= Craft::$app->getPlugins()->isPluginEnabled(self::HANDLE);
    }

    public function getPlugin(): ?object
    {
        return $this->isInstalled() ? Craft::$app->getPlugins()->getPlugin(self::HANDLE) : null;
    }

    /**
     * Open a Pigeon thread for a new ticket, with the ticket's message as its first message.
     *
     * The ticket keeps its own copy of the message either way — `csr_tickets.message` is written
     * before this runs — so a bridge that fails leaves a complete ticket rather than an empty one
     * pointing at a thread that does not exist.
     */
    public function openThread(Ticket $ticket): bool
    {
        if (!$this->isEnabled() || $ticket->id === null || $ticket->pigeonThreadId !== null) {
            return false;
        }

        try {
            $plugin = $this->getPlugin();
            $threads = $plugin->threads ?? null;
            $messages = $plugin->messages ?? null;

            if (!$this->callable($threads, 'createSupportThread') || !$this->callable($messages, 'post')) {
                return false;
            }

            $thread = $threads->createSupportThread(
                (string)$ticket->title,
                $ticket->requesterEmail !== '' ? $ticket->requesterEmail : null,
                $ticket->requesterName,
                $ticket->requesterId,
            );

            $threadId = is_object($thread) ? ($thread->id ?? null) : null;

            if ($threadId === null) {
                return false;
            }

            $ticket->pigeonThreadId = (int)$threadId;

            // Written straight to the column rather than through a full element save: this runs
            // inside the submit pipeline, and re-saving the element here would fire every
            // after-save hook a second time.
            Db::update(Table::TICKETS, ['pigeonThreadId' => (int)$threadId], ['id' => $ticket->id], [], false);

            if ((string)$ticket->message !== '') {
                $this->quietly(fn() => $messages->post($thread, [
                    'body' => (string)$ticket->message,
                    'authorUserId' => $ticket->requesterId,
                    'authorEmail' => $ticket->requesterEmail !== '' ? $ticket->requesterEmail : null,
                    'authorName' => $ticket->requesterName,
                ]));
            }

            return true;
        } catch (Throwable $exception) {
            $this->logFailure('open a thread for ticket #' . $ticket->number, $exception);

            return false;
        }
    }

    /**
     * Put a reply — or an internal note, as one of Pigeon's — into the ticket's thread, so both
     * inboxes tell the same story. A reply that came *from* Pigeon is not sent back.
     */
    public function mirrorReply(Ticket $ticket, Reply $reply): bool
    {
        if (
            !$this->isEnabled()
            || $ticket->pigeonThreadId === null
            || $this->_bringingAcross
            || $reply->source === TicketSource::Pigeon->value
        ) {
            return false;
        }

        try {
            $plugin = $this->getPlugin();
            $thread = $this->callable($plugin->threads ?? null, 'getById') ? $plugin->threads->getById($ticket->pigeonThreadId) : null;

            if ($thread === null || !$this->callable($plugin->messages ?? null, 'post')) {
                return false;
            }

            $this->quietly(fn() => $plugin->messages->post($thread, [
                'body' => $reply->body,
                'authorUserId' => $reply->userId,
                'authorEmail' => $reply->byAgent ? null : ($reply->authorEmail ?? $ticket->requesterEmail),
                'authorName' => $reply->byAgent ? null : ($reply->authorName ?? $ticket->requesterName),
                'isInternalNote' => $reply->isNote,
            ]));

            return true;
        } catch (Throwable $exception) {
            $this->logFailure('mirror a reply on ticket #' . $ticket->number, $exception);

            return false;
        }
    }

    /**
     * The other direction: a message written in Pigeon becomes a reply on the ticket.
     *
     * Registered by name, never by import, so CSR still loads without Pigeon. The event is Pigeon's
     * `Messages::EVENT_AFTER_POST_MESSAGE`; anything that does not look like it is ignored.
     */
    public function registerListener(): void
    {
        Event::on(self::MESSAGES_CLASS, 'afterPostMessage', function(Event $event) {
            if (!$this->_mirroring && $this->isEnabled()) {
                $this->bringAcross($event);
            }
        });
    }

    private function bringAcross(Event $event): void
    {
        $message = $event->message ?? null;
        $thread = $event->thread ?? null;

        if (!is_object($message) || !is_object($thread) || !isset($thread->id) || !empty($message->isSystem)) {
            return;
        }

        try {
            /** @var Ticket|null $ticket */
            $ticket = Ticket::find()->andWhere(['csr_tickets.pigeonThreadId' => (int)$thread->id])->one();

            if ($ticket === null) {
                return;
            }

            $userId = isset($message->authorUserId) ? (int)$message->authorUserId : null;
            $author = $userId ? Craft::$app->getUsers()->getUserById($userId) : null;

            // From the desk when it was written by somebody who may answer tickets and is not the
            // person who raised this one. Anything else — a guest, the requester, a stranger with
            // an account — is the customer's side of the conversation.
            $byAgent = $author !== null
                && $author->id !== $ticket->requesterId
                && ($author->admin || $author->can(Plugin::PERMISSION_MANAGE_TICKETS));

            $this->_bringingAcross = true;

            Plugin::getInstance()->replies->post($ticket, [
                'body' => (string)($message->body ?? ''),
                'isNote' => !empty($message->isInternalNote),
                'byAgent' => $byAgent,
                'userId' => $author?->id,
                'authorName' => $byAgent ? null : ($message->authorName ?? $ticket->requesterName),
                'authorEmail' => $byAgent ? null : ($message->authorEmail ?? $ticket->requesterEmail),
                'source' => TicketSource::Pigeon->value,
                // Pigeon has already told the people on the thread.
                'notify' => false,
            ]);
        } catch (Throwable $exception) {
            $this->logFailure('bring a Pigeon message into ticket', $exception);
        } finally {
            $this->_bringingAcross = false;
        }
    }

    /** Run a Pigeon write without the listener turning it straight back into a CSR reply. */
    private function quietly(callable $write): mixed
    {
        $this->_mirroring = true;

        try {
            return $write();
        } finally {
            $this->_mirroring = false;
        }
    }

    private function callable(mixed $service, string $method): bool
    {
        return is_object($service) && method_exists($service, $method);
    }

    /** The Pigeon thread behind a ticket, for the "open in Pigeon" link on the ticket screen. */
    public function getThreadUrl(Ticket $ticket): ?string
    {
        if ($ticket->pigeonThreadId === null || !$this->isInstalled()) {
            return null;
        }

        return \craft\helpers\UrlHelper::cpUrl('pigeon/threads/' . $ticket->pigeonThreadId);
    }

    private function logFailure(string $what, Throwable $exception): void
    {
        // A warning, not an error: the ticket is fine, the desk can still answer it, and the only
        // thing lost is a convenience. Escalating this to an error trains people to ignore errors.
        Craft::warning(
            sprintf('CSR could not %s through Pigeon: %s', $what, $exception->getMessage()),
            Plugin::LOG_CATEGORY,
        );
    }
}
