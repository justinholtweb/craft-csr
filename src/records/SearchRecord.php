<?php

declare(strict_types=1);

namespace justinholtweb\csr\records;

use craft\db\ActiveRecord;

/**
 * @property int $id
 * @property int $siteId
 * @property string $query
 * @property string $normalizedQuery
 * @property int $resultCount
 * @property int|null $clickedArticleId
 * @property string|null $visitorKey
 * @property int|null $userId
 * @property string $source
 */
class SearchRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return Table::SEARCHES;
    }

}
