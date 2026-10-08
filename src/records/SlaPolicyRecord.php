<?php

declare(strict_types=1);

namespace justinholtweb\csr\records;

use craft\db\ActiveRecord;

/**
 * @property int $id
 * @property string $name
 * @property string $handle
 * @property string|null $description
 * @property bool $enabled
 * @property string|null $conditions
 * @property int|null $firstResponseMinutes
 * @property int|null $resolutionMinutes
 * @property bool $businessHoursOnly
 * @property int|null $escalateAfterMinutes
 * @property string|null $escalateToTeamUid
 * @property bool $notifyOnBreach
 * @property int|null $sortOrder
 * @property string $uid
 */
class SlaPolicyRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return Table::SLA_POLICIES;
    }
}
