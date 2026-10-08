<?php

declare(strict_types=1);

namespace justinholtweb\csr\records;

use craft\db\ActiveRecord;

/**
 * @property int $id
 * @property int $articleId
 * @property int $siteId
 * @property string $date
 * @property int $views
 */
class ViewRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return Table::VIEWS;
    }
}
