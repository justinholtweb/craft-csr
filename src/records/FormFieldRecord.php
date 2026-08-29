<?php

declare(strict_types=1);

namespace justinholtweb\csr\records;

use craft\db\ActiveRecord;

/**
 * @property int $id
 * @property string $name
 * @property string $handle
 * @property string $type
 * @property string|null $instructions
 * @property string|null $placeholder
 * @property bool $required
 * @property bool $enabled
 * @property string|null $options
 * @property string|null $categoryUids
 * @property int|null $sortOrder
 * @property string $uid
 */
class FormFieldRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return Table::FORM_FIELDS;
    }

}
