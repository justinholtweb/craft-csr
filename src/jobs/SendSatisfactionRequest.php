<?php

declare(strict_types=1);

namespace justinholtweb\csr\jobs;

use Craft;
use craft\queue\BaseJob;
use justinholtweb\csr\elements\Ticket;
use justinholtweb\csr\models\Edition;
use justinholtweb\csr\Plugin;

/**
 * "How did we do?", sent some hours after a ticket is resolved.
 *
 * Queued with a delay at the moment of resolution rather than found by a sweep, and everything is
 * checked again when it runs rather than trusted from then: in the meantime the customer may have
 * replied and reopened it, rated it from the ticket page, or the ticket may have been merged or
 * marked as spam. `resolvedAt` is compared so that a ticket resolved, reopened and resolved again
 * is asked once — by the job belonging to the last resolution.
 */
class SendSatisfactionRequest extends BaseJob
{
    public int $ticketId;

    /** The `resolvedAt` this job was queued for, as a Unix timestamp. */
    public int $resolvedAt;

    public function execute($queue): void
    {
        $plugin = Plugin::getInstance();

        if (!Edition::allowsSatisfactionSurvey($plugin->isPro()) || !$plugin->getSettings()->satisfactionEnabled) {
            return;
        }

        /** @var Ticket|null $ticket */
        $ticket = Ticket::find()->id($this->ticketId)->status(null)->one();

        if (
            $ticket === null
            || $ticket->isSpam
            || $ticket->mergedIntoId !== null
            || $ticket->satisfaction !== null
            || $ticket->resolvedAt === null
            || $ticket->resolvedAt->getTimestamp() !== $this->resolvedAt
            || ($ticket->getTicketStatus()?->isOpen() ?? true)
        ) {
            return;
        }

        $plugin->notifications->sendSatisfactionRequest($ticket);
    }

    protected function defaultDescription(): ?string
    {
        return Craft::t('csr', 'Asking how a ticket went');
    }
}
