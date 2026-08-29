<?php

declare(strict_types=1);

namespace justinholtweb\csr\enums;

use Craft;

/** Where a ticket came from. Recorded because "how do people reach us" is a real question. */
enum TicketSource: string
{
    case Web = 'web';
    case Portal = 'portal';
    case ControlPanel = 'cp';
    case Email = 'email';
    case Api = 'api';

    public function label(): string
    {
        return match ($this) {
            self::Web => Craft::t('csr', 'Support form'),
            self::Portal => Craft::t('csr', 'Customer portal'),
            self::ControlPanel => Craft::t('csr', 'Control panel'),
            self::Email => Craft::t('csr', 'Email'),
            self::Api => Craft::t('csr', 'API'),
        };
    }

    public static function fromString(?string $value, self $default = self::Web): self
    {
        return $value !== null ? (self::tryFrom($value) ?? $default) : $default;
    }
}
