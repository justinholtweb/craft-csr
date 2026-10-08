<?php

declare(strict_types=1);

namespace justinholtweb\csr\records;

use craft\db\ActiveRecord;

/**
 * @property int $id
 * @property string $name
 * @property string $handle
 * @property string|null $description
 * @property string $color
 * @property string|null $email
 * @property int|null $sortOrder
 * @property string $uid
 */
class TeamRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return Table::TEAMS;
    }
}
