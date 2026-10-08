<?php

declare(strict_types=1);

namespace justinholtweb\csr\records;

use craft\db\ActiveRecord;

/**
 * @property int $id
 * @property int $articleId
 * @property int $siteId
 * @property int|null $userId
 * @property bool $helpful
 * @property string|null $comment
 * @property string $visitorKey
 */
class RatingRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return Table::RATINGS;
    }
}
