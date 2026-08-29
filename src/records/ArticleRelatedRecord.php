<?php

declare(strict_types=1);

namespace justinholtweb\csr\records;

use craft\db\ActiveRecord;

/**
 * @property int $id
 * @property int $articleId
 * @property int $relatedId
 * @property int|null $sortOrder
 */
class ArticleRelatedRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return Table::ARTICLE_RELATED;
    }
}
