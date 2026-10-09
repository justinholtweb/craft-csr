<?php

declare(strict_types=1);

namespace justinholtweb\csr\events;

use craft\events\CancelableEvent;
use justinholtweb\csr\elements\Ticket;
use justinholtweb\csr\mail\InboundMessage;
use justinholtweb\csr\models\Reply;

/**
 * Fired around the processing of one received email.
 *
 * Before: the message is parsed and has passed the loop guard; set `isValid` to false to drop it
 * (it is recorded as ignored), or change it — a different subject, fewer attachments. After:
 * `ticket` and, for a reply, `reply` say where it went.
 */
class InboundEmailEvent extends CancelableEvent
{
    public ?InboundMessage $message = null;

    /** The `csr_inbound` row. */
    public ?int $inboundId = null;

    /** The ticket it was matched to (before) or landed on (after). */
    public ?Ticket $ticket = null;

    public ?Reply $reply = null;

    /** `ticket`, `reply`, `ignored`, `rejected` — after only. */
    public ?string $outcome = null;
}
