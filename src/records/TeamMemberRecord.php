<?php

declare(strict_types=1);

namespace justinholtweb\csr\records;

use craft\db\ActiveRecord;

/**
 * @property int $id
 * @property int $teamId
 * @property int $userId
 * @property bool $isLead
 */
class TeamMemberRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return Table::TEAM_MEMBERS;
    }

}
