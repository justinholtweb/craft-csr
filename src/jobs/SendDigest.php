<?php

declare(strict_types=1);

namespace justinholtweb\csr\jobs;

use Craft;
use craft\queue\BaseJob;
use justinholtweb\csr\Plugin;

/**
 * Sends the desk digest if it is due. Queued by the web fallback trigger.
 *
 * Decides nothing itself: {@see \justinholtweb\csr\services\Digest::run()} checks the schedule
 * and claims the period, so a job that runs late, twice, or after cron already sent is a no-op.
 */
class SendDigest extends BaseJob
{
    public function execute($queue): void
    {
        Plugin::getInstance()->digest->run();
    }

    protected function defaultDescription(): ?string
    {
        return Craft::t('csr', 'Sending the CSR desk digest');
    }

    public function getTtr(): int
    {
        // A query over the open tickets, then one email per recipient.
        return 900;
    }
}
