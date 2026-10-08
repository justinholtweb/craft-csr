<?php

declare(strict_types=1);

namespace justinholtweb\csr\records;

use craft\db\ActiveRecord;

/**
 * @property int $id
 * @property int $ticketId
 * @property string $type
 * @property int|null $userId
 * @property string|null $data
 */
class EventRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return Table::EVENTS;
    }
}
