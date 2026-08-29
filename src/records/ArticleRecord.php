<?php

declare(strict_types=1);

namespace justinholtweb\csr\records;

use craft\db\ActiveRecord;
use craft\records\Element;
use yii\db\ActiveQueryInterface;

/**
 * @property int $id
 * @property int|null $categoryId
 * @property int|null $authorId
 * @property string|null $excerpt
 * @property string|null $keywords
 * @property string $visibility
 * @property string|null $allowedGroupIds
 * @property bool $featured
 * @property bool $allowRatings
 * @property int $helpfulCount
 * @property int $unhelpfulCount
 * @property int $viewCount
 * @property int|null $sortOrder
 */
class ArticleRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return Table::ARTICLES;
    }

    public function getElement(): ActiveQueryInterface
    {
        return $this->hasOne(Element::class, ['id' => 'id']);
    }
}
