<?php

declare(strict_types=1);

namespace justinholtweb\csr\jobs;

use Craft;
use craft\queue\BaseJob;
use justinholtweb\csr\Plugin;

/**
 * Run the SLA sweep.
 *
 * Queued rather than done inline so that a desk with several thousand open tickets does not stall
 * whichever unlucky page view triggered it. The sweep is idempotent — the stamped breach columns
 * are what stop a ticket being reported twice — so running it more often than necessary costs
 * queries and nothing else.
 */
class CheckSlas extends BaseJob
{
    public ?int $limit = 500;

    public function execute($queue): void
    {
        $counts = Plugin::getInstance()->slas->sweep($this->limit);

        if (array_sum($counts) > 0) {
            Craft::info(sprintf(
                'CSR SLA sweep: %d first-response breaches, %d resolution breaches, %d escalated.',
                $counts['firstResponse'],
                $counts['resolution'],
                $counts['escalated'],
            ), Plugin::LOG_CATEGORY);
        }
    }

    protected function defaultDescription(): ?string
    {
        return Craft::t('csr', 'Checking support targets');
    }
}
