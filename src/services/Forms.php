<?php

declare(strict_types=1);

namespace justinholtweb\csr\services;

use Craft;
use craft\base\Component;
use craft\db\Query;
use craft\events\ConfigEvent;
use craft\helpers\Db;
use craft\helpers\Json;
use craft\helpers\StringHelper;
use justinholtweb\csr\enums\FieldType;
use justinholtweb\csr\models\Category;
use justinholtweb\csr\models\Edition;
use justinholtweb\csr\models\FormField;
use justinholtweb\csr\Plugin;
use justinholtweb\csr\records\FormFieldRecord;
use justinholtweb\csr\records\Table;

/**
 * The questions the support form asks.
 *
 * The built-in ones — name, email, subject, message, category, attachments — are not here. They
 * exist unconditionally, because a support form without them is not a support form, and making
 * them configurable would mean every downstream feature having to cope with a desk that does not
 * collect an email address.
 *
 * These are the site's *own* questions: order number, plan, which browser. Project config, because
 * they are part of the form's definition and a form that differs between staging and production is
 * a form nobody can test.
 */
class Forms extends Component
{
    public const CONFIG_KEY = 'csr.formFields';

    /** @var FormField[]|null */
    private ?array $_fields = null;

    /** @return FormField[] In the order they are asked. */
    public function getAllFields(): array
    {
        if ($this->_fields !== null) {
            return $this->_fields;
        }

        $this->_fields = [];

        /** @var FormFieldRecord[] $records */
        $records = FormFieldRecord::find()
            ->orderBy(['sortOrder' => SORT_ASC, 'name' => SORT_ASC])
            ->all();

        foreach ($records as $record) {
            $this->_fields[] = new FormField([
                'id' => (int)$record->id,
                'name' => $record->name,
                'handle' => $record->handle,
                'type' => $record->type,
                'instructions' => $record->instructions,
                'placeholder' => $record->placeholder,
                'required' => (bool)$record->required,
                'enabled' => (bool)$record->enabled,
                'options' => $record->options,
                'categoryUids' => $record->categoryUids,
                'sortOrder' => $record->sortOrder !== null ? (int)$record->sortOrder : null,
                'uid' => $record->uid,
            ]);
        }

        return $this->_fields;
    }

    /**
     * The questions actually asked for a given subject.
     *
     * On Lite this is always empty, and the form falls back to its built-in fields — which is a
     * working support form, not a crippled one.
     *
     * @return FormField[]
     */
    public function getFieldsFor(?Category $category = null): array
    {
        if (!Edition::allowsCustomFormFields(Plugin::getInstance()->isPro())) {
            return [];
        }

        return array_values(array_filter(
            $this->getAllFields(),
            static fn(FormField $field) => $field->appliesTo($category),
        ));
    }

    public function getFieldById(?int $id): ?FormField
    {
        if ($id === null) {
            return null;
        }

        foreach ($this->getAllFields() as $field) {
            if ($field->id === $id) {
                return $field;
            }
        }

        return null;
    }

    public function getFieldByHandle(string $handle): ?FormField
    {
        foreach ($this->getAllFields() as $field) {
            if ($field->handle === $handle) {
                return $field;
            }
        }

        return null;
    }

    public function getFieldByUid(string $uid): ?FormField
    {
        foreach ($this->getAllFields() as $field) {
            if ($field->uid === $uid) {
                return $field;
            }
        }

        return null;
    }

    /**
     * Read the answers out of an untrusted payload.
     *
     * The direction matters: the *fields* are enumerated and each one pulls its own value out of
     * the request. A value posted under a handle the form does not define is never looked at, and
     * a value of the wrong shape for its field is discarded by
     * {@see FormField::normalizeValue()} rather than stored and dealt with later.
     *
     * @param array<string, mixed> $posted
     * @return array<string, mixed>
     */
    public function collect(array $posted, ?Category $category = null): array
    {
        $answers = [];

        foreach ($this->getFieldsFor($category) as $field) {
            $value = $field->normalizeValue($posted[$field->handle] ?? null);

            if ($field->isAnswered($value)) {
                $answers[$field->handle] = $value;
            }
        }

        return $answers;
    }

    /**
     * Which required questions were not answered.
     *
     * @param array<string, mixed> $answers Already through {@see self::collect()}.
     * @return array<string, string> handle => message
     */
    public function validateAnswers(array $answers, ?Category $category = null): array
    {
        $errors = [];

        foreach ($this->getFieldsFor($category) as $field) {
            if ($field->required && !$field->isAnswered($answers[$field->handle] ?? null)) {
                $errors[$field->handle] = Craft::t('csr', '{name} is required.', ['name' => $field->name]);
            }
        }

        return $errors;
    }

    // Writing
    // -------------------------------------------------------------------------

    public function saveField(FormField $field, bool $runValidation = true): bool
    {
        if (!Edition::allowsCustomFormFields(Plugin::getInstance()->isPro())) {
            $field->addError('name', Craft::t('csr', 'Custom form fields are a Pro feature.'));

            return false;
        }

        if ($runValidation && !$field->validate()) {
            return false;
        }

        $isNew = $field->id === null;

        if ($isNew) {
            $field->uid = StringHelper::UUID();
            $field->sortOrder ??= (int)((new Query())->from([Table::FORM_FIELDS])->max('[[sortOrder]]') ?? 0) + 1;
        } elseif (!$field->uid) {
            $field->uid = Db::uidById(Table::FORM_FIELDS, $field->id);
        }

        Craft::$app->getProjectConfig()->set(
            self::CONFIG_KEY . '.' . $field->uid,
            $field->getConfig(),
            "Save the “{$field->handle}” support form field",
        );

        if ($isNew) {
            $field->id = Db::idByUid(Table::FORM_FIELDS, $field->uid);
        }

        $this->refresh();

        return true;
    }

    /** @param string[] $uids In the order they should be asked. */
    public function reorderFields(array $uids): bool
    {
        $projectConfig = Craft::$app->getProjectConfig();

        foreach ($uids as $index => $uid) {
            if ($this->getFieldByUid((string)$uid) !== null) {
                $projectConfig->set(self::CONFIG_KEY . '.' . $uid . '.sortOrder', $index + 1, 'Reorder support form fields');
            }
        }

        $this->refresh();

        return true;
    }

    /**
     * Delete a question without deleting the answers people gave it.
     *
     * The answers live in `csr_tickets.answers`, keyed by handle, and are left exactly where they
     * are. {@see \justinholtweb\csr\elements\Ticket::getAnsweredQuestions()} still lists them,
     * labelled by handle — throwing away what somebody told you because the form changed afterwards
     * would be worse than an ugly label.
     */
    public function deleteFieldById(int $id): bool
    {
        $field = $this->getFieldById($id);

        if ($field === null) {
            return false;
        }

        Craft::$app->getProjectConfig()->remove(
            self::CONFIG_KEY . '.' . $field->uid,
            "Delete the “{$field->handle}” support form field",
        );

        $this->removeFieldRecord((string)$field->uid);
        $this->refresh();

        return true;
    }

    public function handleChangedField(ConfigEvent $event): void
    {
        $uid = $event->tokenMatches[0];
        $data = $event->newValue;

        $record = FormFieldRecord::findOne(['uid' => $uid]) ?? new FormFieldRecord();
        $record->uid = $uid;
        $record->name = $data['name'];
        $record->handle = $data['handle'];
        $record->type = $data['type'] ?? FieldType::Text->value;
        $record->instructions = $data['instructions'] ?? null;
        $record->placeholder = $data['placeholder'] ?? null;
        $record->required = (bool)($data['required'] ?? false);
        $record->enabled = (bool)($data['enabled'] ?? true);
        $record->options = Json::encode($data['options'] ?? []);
        $record->categoryUids = Json::encode($data['categoryUids'] ?? []);
        $record->sortOrder = $data['sortOrder'] ?? null;
        $record->save(false);

        $this->refresh();
    }

    public function handleDeletedField(ConfigEvent $event): void
    {
        $this->removeFieldRecord($event->tokenMatches[0]);
        $this->refresh();
    }

    private function removeFieldRecord(string $uid): void
    {
        FormFieldRecord::findOne(['uid' => $uid])?->delete();
    }

    public function refresh(): void
    {
        $this->_fields = null;
    }

    /** @return array<string, mixed> */
    public function rebuildProjectConfig(): array
    {
        $config = [];

        /** @var FormFieldRecord[] $records */
        $records = FormFieldRecord::find()->all();

        foreach ($records as $record) {
            $config[$record->uid] = [
                'name' => $record->name,
                'handle' => $record->handle,
                'type' => $record->type,
                'instructions' => $record->instructions ?: null,
                'placeholder' => $record->placeholder ?: null,
                'required' => (bool)$record->required,
                'enabled' => (bool)$record->enabled,
                'options' => Json::decodeIfJson((string)$record->options) ?: [],
                'categoryUids' => Json::decodeIfJson((string)$record->categoryUids) ?: [],
                'sortOrder' => (int)$record->sortOrder,
            ];
        }

        return $config;
    }
}
