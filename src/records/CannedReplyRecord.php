<?php

declare(strict_types=1);

namespace justinholtweb\csr\records;

use craft\db\ActiveRecord;

/**
 * @property int $id
 * @property string $name
 * @property string|null $shortcut
 * @property string $body
 * @property int|null $categoryId
 * @property int|null $teamId
 * @property bool $enabled
 * @property int $useCount
 * @property int|null $sortOrder
 * @property string $uid
 */
class CannedReplyRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return Table::CANNED_REPLIES;
    }

}
