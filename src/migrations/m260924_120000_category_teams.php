<?php

declare(strict_types=1);

namespace justinholtweb\csr\migrations;

use craft\db\Migration;
use justinholtweb\csr\records\Table;

/**
 * A category can route its tickets to a team.
 *
 * On the category rather than on a routing table of its own, for the reason the whole plugin is
 * built on: one taxonomy, read from both ends. The category that decides which articles somebody
 * is offered also decides who answers them when the articles were not enough.
 */
class m260924_120000_category_teams extends Migration
{
    public function safeUp(): bool
    {
        if (!$this->db->columnExists(Table::CATEGORIES, 'teamId')) {
            $this->addColumn(Table::CATEGORIES, 'teamId', $this->integer()->after('acceptsTickets'));
            $this->createIndex(null, Table::CATEGORIES, ['teamId']);
            $this->addForeignKey(null, Table::CATEGORIES, ['teamId'], Table::TEAMS, ['id'], 'SET NULL', null);
        }

        return true;
    }

    public function safeDown(): bool
    {
        echo "m260924_120000_category_teams cannot be reverted.\n";

        return false;
    }
}
