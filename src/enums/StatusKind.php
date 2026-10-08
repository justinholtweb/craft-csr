<?php

declare(strict_types=1);

namespace justinholtweb\csr\enums;

use Craft;

/**
 * What a ticket status *means* to CSR, as opposed to what it is called.
 *
 * A site can call a status "Waiting on the customer", "Snoozed" or "Ballen Sie", and CSR still has
 * to answer three questions about it without asking anybody: is this ticket finished, does the SLA
 * clock run while it sits here, and does it belong in the agent's queue. The name is the site's;
 * the kind is the contract.
 *
 * The one that earns its place is **Pending**. A ticket waiting on the *customer* must not burn
 * the resolution SLA — otherwise every policy is breached by people who reply slowly to their own
 * support requests, and the SLA report becomes a measure of customer responsiveness rather than
 * of the desk.
 */
enum StatusKind : string
{
    case New = 'new';
    case Open = 'open';
    case Pending = 'pending';
    case Resolved = 'resolved';
    case Closed = 'closed';

    public function label(): string
    {
        return match ($this) {
            self::New => Craft::t('csr', 'New'),
            self::Open => Craft::t('csr', 'Open'),
            self::Pending => Craft::t('csr', 'Pending — waiting on the customer'),
            self::Resolved => Craft::t('csr', 'Resolved'),
            self::Closed => Craft::t('csr', 'Closed'),
        };
    }

    /** Still somebody's problem: it belongs in a queue and counts towards "open tickets". */
    public function isOpen(): bool
    {
        return match ($this) {
            self::New, self::Open, self::Pending => true,
            self::Resolved, self::Closed => false,
        };
    }

    /** The resolution clock stops here. Resolved and closed are both finished. */
    public function stopsResolutionClock(): bool
    {
        return !$this->isOpen();
    }

    /**
     * The clock is *paused* rather than stopped: time spent here is subtracted from the elapsed
     * time when the ticket comes back, instead of counting against the desk.
     */
    public function pausesClock(): bool
    {
        return $this === self::Pending;
    }

    /** A ticket nobody has replied to yet. Drives the "needs a first response" queue. */
    public function isUntouched(): bool
    {
        return $this === self::New;
    }

    public static function fromString(?string $value, self $default = self::Open): self
    {
        return $value !== null ? (self::tryFrom($value) ?? $default) : $default;
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }
}
