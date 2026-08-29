<?php

declare(strict_types=1);

namespace justinholtweb\csr\records;

use craft\db\ActiveRecord;

/**
 * @property int $id
 * @property int $ticketId
 * @property int|null $replyId
 * @property int|null $assetId
 * @property string $filename
 * @property int $size
 * @property string|null $kind
 * @property int|null $uploadedBy
 */
class AttachmentRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return Table::ATTACHMENTS;
    }

}
