<?php

declare(strict_types=1);

namespace justinholtweb\csr\jobs;

use Craft;
use craft\queue\BaseJob;
use justinholtweb\csr\Plugin;

/**
 * Turns one received email into a ticket or a reply.
 *
 * Queued by the webhook so the provider gets its 200 straight away — a slow mail server, a big
 * attachment or a busy database must never look to Postmark like a failed delivery, because a
 * failed delivery is retried and every retry is another copy. Idempotent: a row that is no longer
 * `queued` is left alone, so a job that runs twice does the work once.
 */
class ProcessInboundEmail extends BaseJob
{
    public int $inboundId = 0;

    public function execute($queue): void
    {
        Plugin::getInstance()->inbound->process($this->inboundId);
    }

    protected function defaultDescription(): ?string
    {
        return Craft::t('csr', 'Processing an inbound support email');
    }
}
