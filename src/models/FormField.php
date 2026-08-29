<?php

declare(strict_types=1);

namespace justinholtweb\csr\models;

use Craft;
use craft\base\Model;
use craft\helpers\DateTimeHelper;
use craft\helpers\StringHelper;
use craft\validators\HandleValidator;
use justinholtweb\csr\enums\FieldType;

/**
 * A question the support form asks, beyond the built-in ones.
 *
 * This is the *opposite direction* from the Ticket element's Craft field layout, and the
 * distinction matters:
 *
 * - A **form field** is what the person in trouble answers. It is validated on the server, against
 *   a hostile request, and rendered in the site's own markup.
 * - The Ticket element's **field layout** is what the desk records afterwards — the account
 *   number somebody looked up, the internal reference — and the requester never sees it.
 *
 * A Craft field layout cannot do the first job. It renders control panel HTML for a trusted
 * author, and has no concept of "a stranger must fill this in".
 */
class FormField extends Model
{
    public ?int $id = null;
    public string $name = '';
    public string $handle = '';
    public string $type = FieldType::Text->value;
    public ?string $instructions = null;
    public ?string $placeholder = null;
    public bool $required = false;
    public bool $enabled = true;
    public ?int $sortOrder = null;
    public ?string $uid = null;

    /** @var array<int, array{label: string, value: string}> */
    private array $_options = [];

    /** @var array<int, string> Category uids this question applies to. Empty means all of them. */
    private array $_categoryUids = [];

    public function __toString(): string
    {
        return $this->name;
    }

    public function rules(): array
    {
        return [
            [['name', 'handle'], 'required'],
            [['name', 'handle'], 'string', 'max' => 255],
            [['handle'], HandleValidator::class],
            [['type'], 'in', 'range' => array_column(FieldType::cases(), 'value')],
            [['sortOrder'], 'integer'],
            // The built-in fields own these names on the posted form; a custom question sharing
            // one would overwrite the requester's own email address with their answer.
            // `in` with `not`, not `notIn` — Yii has no validator of that name, and an unknown
            // one is not a validation failure but a `NotInstantiableException` thrown the first
            // time anything validates the model.
            [
                ['handle'],
                'in',
                'not' => true,
                'range' => ['name', 'email', 'subject', 'message', 'category', 'priority', 'attachments', 'action', 'token', 'p'],
                'message' => Craft::t('csr', '“{value}” is used by the support form itself. Pick another handle.'),
            ],
            [['options'], 'validateOptions', 'skipOnEmpty' => false],
        ];
    }

    /**
     * A dropdown with no options is a dropdown nobody can answer.
     *
     * `skipOnEmpty => false` because an empty array counts as empty to Yii, and this rule exists
     * precisely for the empty case — without it, the one thing it checks is the one thing it never
     * runs on.
     */
    public function validateOptions(string $attribute): void
    {
        if ($this->getFieldType()->hasOptions() && $this->_options === []) {
            $this->addError($attribute, Craft::t('csr', 'Add at least one option.'));
        }
    }

    public function getFieldType(): FieldType
    {
        return FieldType::fromString($this->type);
    }

    // Options
    // -------------------------------------------------------------------------

    /** @return array<int, array{label: string, value: string}> */
    public function getOptions(): array
    {
        return $this->_options;
    }

    /** Accepts stored JSON, editable-table rows, or a plain list of labels. */
    public function setOptions(mixed $value): void
    {
        if (is_string($value)) {
            $value = json_decode($value, true) ?? preg_split('/\R/', $value);
        }

        $options = [];

        foreach (is_array($value) ? $value : [] as $row) {
            if (is_array($row)) {
                $label = trim((string)($row['label'] ?? $row['value'] ?? ''));
                $optionValue = trim((string)($row['value'] ?? $row['label'] ?? ''));
            } else {
                $label = $optionValue = trim((string)$row);
            }

            if ($label === '') {
                continue;
            }

            $options[] = [
                'label' => $label,
                // A value is what gets stored forever; a label is what somebody re-words next
                // month. Deriving the value from the label once, at creation, keeps old answers
                // readable after the wording changes.
                'value' => $optionValue !== '' ? $optionValue : StringHelper::toKebabCase($label),
            ];
        }

        $this->_options = $options;
    }

    /** @return array<string, string> value => label, for a select. */
    public function getOptionMap(): array
    {
        $map = [];

        foreach ($this->_options as $option) {
            $map[$option['value']] = $option['label'];
        }

        return $map;
    }

    // Category conditions
    // -------------------------------------------------------------------------

    /** @return array<int, string> */
    public function getCategoryUids(): array
    {
        return $this->_categoryUids;
    }

    public function setCategoryUids(mixed $value): void
    {
        if (is_string($value)) {
            $value = json_decode($value, true);
        }

        $this->_categoryUids = array_values(array_filter(array_map(
            'strval',
            is_array($value) ? $value : [],
        ), static fn(string $uid) => $uid !== ''));
    }

    /** Whether the question is asked for the chosen subject. No uids means always. */
    public function appliesTo(?Category $category): bool
    {
        if (!$this->enabled) {
            return false;
        }

        if ($this->_categoryUids === []) {
            return true;
        }

        return $category !== null && in_array((string)$category->uid, $this->_categoryUids, true);
    }

    // Answers
    // -------------------------------------------------------------------------

    /**
     * Turns whatever was posted into something worth storing, or null if it was not an answer.
     *
     * Everything here assumes the request is hostile: options are matched against the *field's*
     * list rather than trusted, multi-value answers are only accepted from types that have them,
     * and every string is length-capped so a text area cannot be used to fill the disk.
     */
    public function normalizeValue(mixed $value): mixed
    {
        $type = $this->getFieldType();

        if ($type->isMultiValue()) {
            $values = is_array($value) ? $value : ($value === null || $value === '' ? [] : [$value]);
            $allowed = array_keys($this->getOptionMap());

            return array_values(array_filter(array_map('strval', $values), static fn(string $item) => in_array($item, $allowed, true)));
        }

        if (is_array($value)) {
            // Only checkboxes legitimately post an array. Anything else doing so is somebody
            // trying `answers[subject][]=…` to see what happens.
            return null;
        }

        $value = trim((string)($value ?? ''));

        if ($value === '') {
            return null;
        }

        if ($type->hasOptions()) {
            return array_key_exists($value, $this->getOptionMap()) ? $value : null;
        }

        return match ($type) {
            FieldType::Checkbox => true,
            FieldType::Number => is_numeric($value) ? $value + 0 : null,
            FieldType::Email => filter_var($value, FILTER_VALIDATE_EMAIL) !== false ? $value : null,
            FieldType::Url => filter_var($value, FILTER_VALIDATE_URL) !== false ? $value : null,
            FieldType::Date => DateTimeHelper::toDateTime($value) !== false ? $value : null,
            FieldType::Textarea => mb_substr($value, 0, 5000),
            default => mb_substr($value, 0, 500),
        };
    }

    /** Whether a normalised answer counts as having been given, for the `required` check. */
    public function isAnswered(mixed $value): bool
    {
        return match (true) {
            $value === null, $value === '', $value === [] => false,
            $value === false => $this->getFieldType() !== FieldType::Checkbox,
            default => true,
        };
    }

    /** The answer as a person would read it, for the ticket screen and the notification email. */
    public function formatValue(mixed $value): string
    {
        if ($value === null || $value === []) {
            return '';
        }

        $type = $this->getFieldType();

        if ($type === FieldType::Checkbox) {
            return $value ? Craft::t('csr', 'Yes') : Craft::t('csr', 'No');
        }

        if ($type->hasOptions()) {
            $map = $this->getOptionMap();
            $values = is_array($value) ? $value : [$value];

            return implode(', ', array_map(static fn($item) => $map[(string)$item] ?? (string)$item, $values));
        }

        return is_array($value) ? implode(', ', array_map('strval', $value)) : (string)$value;
    }

    /** @return array<string, mixed> */
    public function getConfig(): array
    {
        return [
            'name' => $this->name,
            'handle' => $this->handle,
            'type' => $this->type,
            'instructions' => $this->instructions ?: null,
            'placeholder' => $this->placeholder ?: null,
            'required' => $this->required,
            'enabled' => $this->enabled,
            'options' => $this->_options,
            'categoryUids' => $this->_categoryUids,
            'sortOrder' => (int)$this->sortOrder,
        ];
    }
}
