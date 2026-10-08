<?php

declare(strict_types=1);

namespace justinholtweb\csr\records;

use craft\db\ActiveRecord;

/**
 * @property int $id
 * @property int $siteId
 * @property string $visitorKey
 * @property string|null $query
 * @property int|null $categoryId
 * @property string|null $suggestedIds
 * @property int|null $clickedArticleId
 * @property string $outcome
 * @property int|null $ticketId
 */
class DeflectionRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return Table::DEFLECTIONS;
    }
}
