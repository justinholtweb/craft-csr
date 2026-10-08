<?php

declare(strict_types=1);

namespace justinholtweb\csr\enums;

use Craft;

/**
 * Who may read an article.
 *
 * The gate is applied in the *query*, not in the template, so a restricted article never reaches a
 * listing, a search result, a deflection suggestion or a sitemap it should not be in. Restricting
 * only the article page while the search index still quotes its first sentence is the commonest
 * way a knowledge base leaks.
 */
enum Visibility : string
{
    case Public = 'public';
    case Users = 'users';
    case Groups = 'groups';

    public function label(): string
    {
        return match ($this) {
            self::Public => Craft::t('csr', 'Anyone'),
            self::Users => Craft::t('csr', 'Logged-in users'),
            self::Groups => Craft::t('csr', 'Specific user groups'),
        };
    }

    public function isRestricted(): bool
    {
        return $this !== self::Public;
    }

    public static function fromString(?string $value, self $default = self::Public): self
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
