<?php

declare(strict_types=1);

namespace justinholtweb\csr\records;

use craft\db\ActiveRecord;

/**
 * @property int $id
 * @property int|null $parentId
 * @property string $name
 * @property string $handle
 * @property string|null $description
 * @property string|null $icon
 * @property string $color
 * @property bool $enabled
 * @property bool $acceptsTickets
 * @property int|null $sortOrder
 * @property string $uid
 */
class CategoryRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return Table::CATEGORIES;
    }

}
