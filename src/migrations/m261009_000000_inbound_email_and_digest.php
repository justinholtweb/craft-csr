<?php

declare(strict_types=1);

namespace justinholtweb\csr\migrations;

use craft\db\Migration;
use craft\db\Query;
use craft\helpers\StringHelper;
use justinholtweb\csr\mail\Addresses;
use justinholtweb\csr\records\Table;
use justinholtweb\csr\services\Digest;
use justinholtweb\csr\services\Inbound;

/**
 * Email in, and the digest out.
 *
 * - `csr_tickets.replyToken`, the tag in each ticket's reply address, backfilled for every ticket
 *   that already exists so a reply to an email sent before the upgrade still finds its ticket once
 *   inbound email is switched on.
 * - `csr_inbound`, one row per received email: the dedupe key, the rate-limit count and the audit
 *   trail of what became of it.
 * - `csr_emailthreads`, the Message-IDs CSR has sent and received per ticket, for the replies whose
 *   reply address was lost on the way.
 * - `csr_digests`, the scheduled digest's "last sent" marker.
 */
class m261009_000000_inbound_email_and_digest extends Migration
{
    public function safeUp(): bool
    {
        if (!$this->db->columnExists(Table::TICKETS, 'replyToken')) {
            $this->addColumn(Table::TICKETS, 'replyToken', (string)$this->char(32)->after('reference'));

            foreach ((new Query())->select(['id'])->from([Table::TICKETS])->column($this->db) as $id) {
                $this->update(Table::TICKETS, [
                    'replyToken' => StringHelper::randomStringWithChars(Addresses::TOKEN_ALPHABET, Addresses::TOKEN_LENGTH),
                ], ['id' => (int)$id], [], false);
            }

            $this->createIndex(null, Table::TICKETS, ['replyToken'], true);
        }

        if (!$this->db->tableExists(Table::INBOUND)) {
            Inbound::createTables($this);
        }

        if (!$this->db->tableExists(Table::DIGESTS)) {
            Digest::createTable($this);
        }

        return true;
    }

    public function safeDown(): bool
    {
        echo "m261009_000000_inbound_email_and_digest cannot be reverted.\n";

        return false;
    }
}
