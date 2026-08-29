<?php

declare(strict_types=1);

namespace justinholtweb\csr\enums;

use Craft;

/**
 * What happened to a ticket.
 *
 * `csr_ticketevents` is append-only, and this is why: a `statusId` column records where a ticket
 * *is*. It cannot record who moved it there, when, or what it was before — and that is exactly the
 * question asked three months later when a customer says nobody ever got back to them.
 */
enum EventType: string
{
    case Created = 'created';
    case Replied = 'replied';
    case Noted = 'noted';
    case StatusChanged = 'status';
    case PriorityChanged = 'priority';
    case Assigned = 'assigned';
    case Unassigned = 'unassigned';
    case TeamChanged = 'team';
    case CategoryChanged = 'category';
    case SlaApplied = 'sla_applied';
    case SlaBreached = 'sla_breached';
    case SlaEscalated = 'sla_escalated';
    case Merged = 'merged';
    case Reopened = 'reopened';
    case Rated = 'rated';
    case Viewed = 'viewed';

    /**
     * Whether the requester may see this entry in their own timeline.
     *
     * Internal notes, SLA machinery and "an agent opened your ticket" are the desk's business.
     * Showing a customer that their ticket breached its SLA twice is not transparency, it is an
     * invitation to a conversation nobody at the desk was given the chance to prepare for.
     */
    public function isPublic(): bool
    {
        return match ($this) {
            self::Created, self::Replied, self::StatusChanged, self::Rated => true,
            default => false,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Created => Craft::t('csr', 'Ticket raised'),
            self::Replied => Craft::t('csr', 'Reply added'),
            self::Noted => Craft::t('csr', 'Internal note added'),
            self::StatusChanged => Craft::t('csr', 'Status changed'),
            self::PriorityChanged => Craft::t('csr', 'Priority changed'),
            self::Assigned => Craft::t('csr', 'Assigned'),
            self::Unassigned => Craft::t('csr', 'Unassigned'),
            self::TeamChanged => Craft::t('csr', 'Team changed'),
            self::CategoryChanged => Craft::t('csr', 'Category changed'),
            self::SlaApplied => Craft::t('csr', 'SLA applied'),
            self::SlaBreached => Craft::t('csr', 'SLA breached'),
            self::SlaEscalated => Craft::t('csr', 'Escalated'),
            self::Merged => Craft::t('csr', 'Merged'),
            self::Reopened => Craft::t('csr', 'Reopened'),
            self::Rated => Craft::t('csr', 'Rated by the customer'),
            self::Viewed => Craft::t('csr', 'Viewed'),
        };
    }
}
