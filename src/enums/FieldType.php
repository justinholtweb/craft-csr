<?php

declare(strict_types=1);

namespace justinholtweb\csr\enums;

use Craft;

/**
 * The kinds of question the support form can ask.
 *
 * A short, closed list on purpose. These fields are rendered in the *site's* markup and validated
 * on the server against an anonymous request — every type here has an answer to "what does a
 * hostile value of this look like". A Craft field layout could not do this job: it renders control
 * panel HTML for a trusted author and has no concept of "a stranger must fill this in".
 */
enum FieldType: string
{
    case Text = 'text';
    case Textarea = 'textarea';
    case Email = 'email';
    case Url = 'url';
    case Number = 'number';
    case Date = 'date';
    case Select = 'select';
    case Radio = 'radio';
    case Checkboxes = 'checkboxes';
    case Checkbox = 'checkbox';
    case Hidden = 'hidden';

    public function label(): string
    {
        return match ($this) {
            self::Text => Craft::t('csr', 'Single-line text'),
            self::Textarea => Craft::t('csr', 'Multi-line text'),
            self::Email => Craft::t('csr', 'Email address'),
            self::Url => Craft::t('csr', 'URL'),
            self::Number => Craft::t('csr', 'Number'),
            self::Date => Craft::t('csr', 'Date'),
            self::Select => Craft::t('csr', 'Dropdown'),
            self::Radio => Craft::t('csr', 'Radio buttons'),
            self::Checkboxes => Craft::t('csr', 'Checkboxes'),
            self::Checkbox => Craft::t('csr', 'Single checkbox'),
            self::Hidden => Craft::t('csr', 'Hidden'),
        };
    }

    /** Types whose answers come from a fixed list, so anything else posted is discarded. */
    public function hasOptions(): bool
    {
        return match ($this) {
            self::Select, self::Radio, self::Checkboxes => true,
            default => false,
        };
    }

    /** Types that legitimately produce more than one value. */
    public function isMultiValue(): bool
    {
        return $this === self::Checkboxes;
    }

    /**
     * Whether an answer to this is worth feeding to the deflection search.
     *
     * "What is going wrong" is; a date, a checkbox or an order number is not — searching the
     * knowledge base for `#10432` returns nothing and dilutes the terms that would have matched.
     */
    public function isSearchable(): bool
    {
        return match ($this) {
            self::Text, self::Textarea, self::Select, self::Radio => true,
            default => false,
        };
    }

    public static function fromString(?string $value, self $default = self::Text): self
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
