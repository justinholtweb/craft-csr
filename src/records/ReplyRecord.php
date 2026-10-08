<?php

declare(strict_types=1);

namespace justinholtweb\csr\records;

use craft\db\ActiveRecord;

/**
 * @property int $id
 * @property int $ticketId
 * @property int|null $userId
 * @property string|null $authorName
 * @property string|null $authorEmail
 * @property string $body
 * @property bool $isNote
 * @property bool $byAgent
 * @property int|null $cannedReplyId
 * @property string|null $source
 * @property string|null $ipAddress
 */
class ReplyRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return Table::REPLIES;
    }
}
