<?php

declare(strict_types=1);

namespace justinholtweb\csr\records;

use craft\db\ActiveRecord;

/**
 * @property int $id
 * @property string $name
 * @property string $handle
 * @property string $kind
 * @property string $color
 * @property string|null $description
 * @property bool $isDefault
 * @property bool $notifyRequester
 * @property string|null $emailSubject
 * @property string|null $emailBody
 * @property int|null $sortOrder
 * @property string $uid
 */
class StatusRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return Table::STATUSES;
    }
}
