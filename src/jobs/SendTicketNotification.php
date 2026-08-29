<?php

declare(strict_types=1);

namespace justinholtweb\csr\jobs;

use Craft;
use craft\queue\BaseJob;
use justinholtweb\csr\elements\Ticket;
use justinholtweb\csr\Plugin;

/**
 * Send one ticket notification, out of the request that caused it.
 *
 * Mail goes through the queue by default because a slow SMTP server shows up to somebody in
 * trouble as a support form that hangs — and, worse, as a support form some of them submit twice.
 *
 * The job carries the ticket's **ID** and a small context array, never the ticket itself: a job
 * runs later, and the ticket may have been answered, reassigned or resolved in between. Reloading
 * it means the email describes the ticket as it is when the email is sent.
 */
class SendTicketNotification extends BaseJob
{
    public int $ticketId;

    /** Which of the notifications to send. See `Notifications::deliver()`. */
    public string $kind = 'raised';

    /** @var array<string, mixed> */
    public array $context = [];

    public function execute($queue): void
    {
        /** @var Ticket|null $ticket */
        $ticket = Ticket::find()->id($this->ticketId)->isSpam(null)->one();

        if ($ticket === null) {
            // Deleted between raising and sending. Not an error: there is nothing to say about a
            // ticket that no longer exists, and failing the job would retry it forever.
            return;
        }

        Plugin::getInstance()->notifications->deliver($ticket, $this->kind, $this->context);
    }

    protected function defaultDescription(): ?string
    {
        return Craft::t('csr', 'Sending a support notification');
    }
}
