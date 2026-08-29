<?php

declare(strict_types=1);

namespace justinholtweb\csr\services;

use Craft;
use craft\base\Component;
use craft\helpers\Db;
use justinholtweb\csr\elements\Ticket;
use justinholtweb\csr\models\Reply;
use justinholtweb\csr\Plugin;
use justinholtweb\csr\records\Table;
use Throwable;

/**
 * The bridge to Pigeon, when it is installed.
 *
 * CSR and Pigeon overlap on exactly one thing — a threaded back-and-forth with somebody who may
 * not have an account — and Pigeon does that better, with read receipts, guest tokens, an inbox
 * and a control panel widget built for reading conversations. Where both are installed and the
 * bridge is switched on, a ticket opens a Pigeon thread and replies flow into it, leaving CSR to
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

    private ?bool $_available = null;

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
     * Open a Pigeon thread for a new ticket.
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
            $threads = $this->getPlugin()?->threads ?? null;

            if ($threads === null || !method_exists($threads, 'createThread')) {
                return false;
            }

            $thread = $threads->createThread([
                'subject' => (string)$ticket->title,
                'guestEmail' => $ticket->requesterEmail,
                'guestName' => $ticket->requesterName,
                'userId' => $ticket->requesterId,
                'body' => (string)$ticket->message,
            ]);

            $threadId = is_object($thread) ? ($thread->id ?? null) : null;

            if ($threadId === null) {
                return false;
            }

            $ticket->pigeonThreadId = (int)$threadId;

            // Written straight to the column rather than through a full element save: this runs
            // inside the submit pipeline, and re-saving the element here would fire every
            // after-save hook a second time.
            Db::update(Table::TICKETS, ['pigeonThreadId' => (int)$threadId], ['id' => $ticket->id], [], false);

            return true;
        } catch (Throwable $exception) {
            $this->logFailure('open a thread for ticket #' . $ticket->number, $exception);

            return false;
        }
    }

    /** Put a reply into the ticket's Pigeon thread, so both inboxes tell the same story. */
    public function mirrorReply(Ticket $ticket, Reply $reply): bool
    {
        if (!$this->isEnabled() || $ticket->pigeonThreadId === null || $reply->isNote) {
            return false;
        }

        try {
            $messages = $this->getPlugin()?->messages ?? null;

            if ($messages === null || !method_exists($messages, 'post')) {
                return false;
            }

            $messages->post([
                'threadId' => $ticket->pigeonThreadId,
                'body' => $reply->body,
                'userId' => $reply->userId,
                'authorEmail' => $reply->authorEmail ?? $ticket->requesterEmail,
            ]);

            return true;
        } catch (Throwable $exception) {
            $this->logFailure('mirror a reply on ticket #' . $ticket->number, $exception);

            return false;
        }
    }

    /** The Pigeon thread behind a ticket, for the "open in Pigeon" link on the ticket screen. */
    public function getThreadUrl(Ticket $ticket): ?string
    {
        if ($ticket->pigeonThreadId === null || !$this->isInstalled()) {
            return null;
        }

        return \craft\helpers\UrlHelper::cpUrl('pigeon/' . $ticket->pigeonThreadId);
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
