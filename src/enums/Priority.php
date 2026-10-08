<?php

declare(strict_types=1);

namespace justinholtweb\csr\enums;

use Craft;

/**
 * How urgent a ticket is.
 *
 * A fixed four rather than a configurable list, and that is deliberate. Priority is the axis SLA
 * policies are written against, and a site that can invent priorities can write a policy that
 * matches a priority nobody can select any more. Four levels is also as many as a support team
 * can hold a shared meaning for — the fifth is always "quite urgent", and it means nothing.
 */
enum Priority : string
{
    case Low = 'low';
    case Normal = 'normal';
    case High = 'high';
    case Urgent = 'urgent';

    public function label(): string
    {
        return match ($this) {
            self::Low => Craft::t('csr', 'Low'),
            self::Normal => Craft::t('csr', 'Normal'),
            self::High => Craft::t('csr', 'High'),
            self::Urgent => Craft::t('csr', 'Urgent'),
        };
    }

    /** A Craft status colour, so priority reads the same in the index as everywhere else. */
    public function color(): string
    {
        return match ($this) {
            self::Low => 'gray',
            self::Normal => 'blue',
            self::High => 'orange',
            self::Urgent => 'red',
        };
    }

    /** Higher sorts first. Used for the "what should I pick up next" ordering. */
    public function weight(): int
    {
        return match ($this) {
            self::Low => 0,
            self::Normal => 1,
            self::High => 2,
            self::Urgent => 3,
        };
    }

    public static function fromString(?string $value, self $default = self::Normal): self
    {
        return $value !== null ? (self::tryFrom($value) ?? $default) : $default;
    }

    /** @return array<string, string> value => label, for a select. */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }
}
