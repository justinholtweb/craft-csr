<?php

declare(strict_types=1);

namespace justinholtweb\csr\events;

use craft\events\CancelableEvent;
use justinholtweb\csr\elements\Ticket;
use justinholtweb\csr\models\Reply;
use justinholtweb\csr\models\Status;

/**
 * Something happened to a ticket, and other code may want to know or to stop it.
 *
 * Extends `craft\events\CancelableEvent` rather than `yii\base\Event`: `$event->isValid` is
 * declared there, and a "cancellable" event extending the plain base throws
 * `UnknownPropertyException` the first time anything reads it — at runtime, in the one code path
 * the event exists for.
 */
class TicketEvent extends CancelableEvent
{
    public Ticket $ticket;

    /** Where it was before, on a status change. */
    public ?Status $previousStatus = null;

    /** The reply that caused this, where one did. */
    public ?Reply $reply = null;
}
