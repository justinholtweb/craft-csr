<?php

declare(strict_types=1);

namespace justinholtweb\csr\records;

use craft\db\ActiveRecord;

/**
 * @property int $id
 * @property int $siteId
 * @property bool $enabledByDefault
 * @property string|null $uriFormat
 * @property string|null $template
 * @property string|null $baseUri
 * @property string $uid
 */
class ArticleSiteSettingsRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return Table::ARTICLES_SITES;
    }

}
