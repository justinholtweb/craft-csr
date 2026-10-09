<?php

declare(strict_types=1);

namespace justinholtweb\csr\console\controllers;

use craft\console\Controller;
use craft\helpers\Console;
use justinholtweb\csr\Plugin;
use justinholtweb\csr\services\Inbound;
use RuntimeException;
use yii\console\ExitCode;

/**
 * `csr/inbound` — email in, from a terminal.
 *
 *     php craft csr/inbound/poll              # read unread mail from the IMAP mailbox (cron)
 *     php craft csr/inbound/import mail.eml   # one raw message; `-` reads standard input
 *     php craft csr/inbound/process           # handle anything still queued, now
 *     php craft csr/inbound/retry             # re-queue failed emails
 *     php craft csr/inbound/log               # what the last emails became
 *
 * `import -` is also a way in on its own: an MTA alias that pipes mail to
 * `php craft csr/inbound/import -` needs no provider and no IMAP extension.
 */
class InboundController extends Controller
{
    public $defaultAction = 'log';

    /** How many messages to take, or rows to show. */
    public int $limit = 50;

    /** Process here and now rather than queueing a job per email. */
    public bool $sync = false;

    public function options($actionID): array
    {
        $options = parent::options($actionID);

        if (in_array($actionID, ['poll', 'import'], true)) {
            $options[] = 'sync';
        }

        if (in_array($actionID, ['poll', 'process', 'log'], true)) {
            $options[] = 'limit';
        }

        return $options;
    }

    /**
     * Read unread messages from the configured IMAP mailbox.
     *
     * Needs PHP's imap extension. Without it this says so and exits 78 (configuration error)
     * rather than failing in a way that looks like a mail problem.
     */
    public function actionPoll(): int
    {
        if (($code = $this->requireEnabled()) !== null) {
            return $code;
        }

        try {
            $counts = Plugin::getInstance()->inbound->poll($this->limit, !$this->sync);
        } catch (RuntimeException $exception) {
            $this->stderr($exception->getMessage() . "\n", Console::FG_RED);

            return Inbound::imapAvailable() ? ExitCode::UNAVAILABLE : ExitCode::CONFIG;
        }

        $this->stdout(sprintf(
            "Fetched %d: %d accepted, %d already seen, %d refused.\n",
            $counts['fetched'],
            $counts['accepted'],
            $counts['duplicates'],
            $counts['rejected'],
        ), Console::FG_GREEN);

        return ExitCode::OK;
    }

    /**
     * Take in one raw RFC 822 message from a file, or from standard input with `-`.
     *
     * @param string $file Path to a `.eml` file, or `-`.
     */
    public function actionImport(string $file = '-'): int
    {
        if (($code = $this->requireEnabled()) !== null) {
            return $code;
        }

        $raw = $file === '-' ? (string)stream_get_contents(STDIN) : (is_file($file) ? (string)file_get_contents($file) : '');

        if (trim($raw) === '') {
            $this->stderr("Nothing to import.\n", Console::FG_RED);

            return ExitCode::NOINPUT;
        }

        $result = Plugin::getInstance()->inbound->importRaw($raw, 'import', !$this->sync);
        $this->stdout("Result: {$result['result']}" . ($result['id'] !== null ? " (inbound #{$result['id']})" : '') . "\n");

        return $result['result'] === Inbound::RESULT_ACCEPTED || $result['result'] === Inbound::RESULT_DUPLICATE
            ? ExitCode::OK
            : ExitCode::DATAERR;
    }

    /** Process queued emails now, for a site without a queue runner. */
    public function actionProcess(): int
    {
        $count = Plugin::getInstance()->inbound->processQueued($this->limit);
        $this->stdout("Processed {$count} queued emails.\n", Console::FG_GREEN);

        return ExitCode::OK;
    }

    /** Put failed emails back in the queue. */
    public function actionRetry(): int
    {
        $count = Plugin::getInstance()->inbound->retryFailed();
        $this->stdout("Re-queued {$count} emails.\n", Console::FG_GREEN);

        return ExitCode::OK;
    }

    /** The latest received emails and what became of them. */
    public function actionLog(): int
    {
        $rows = Plugin::getInstance()->inbound->recent($this->limit);

        if ($rows === []) {
            $this->stdout("No email has been received yet.\n");

            return ExitCode::OK;
        }

        foreach ($rows as $row) {
            $this->stdout(sprintf(
                "#%-6d %-19s %-9s %-12s %-30s %s%s\n",
                (int)$row['id'],
                (string)$row['dateCreated'],
                (string)$row['provider'],
                (string)$row['status'],
                mb_strimwidth((string)$row['fromEmail'], 0, 30, '…'),
                $row['ticketId'] !== null ? 'ticket ' . $row['ticketId'] . ' ' : '',
                (string)($row['reason'] ?? ''),
            ));
        }

        return ExitCode::OK;
    }

    private function requireEnabled(): ?int
    {
        if (!Plugin::getInstance()->inbound->isEnabled()) {
            $this->stderr("Inbound email is switched off (or this is not CSR Pro).\n", Console::FG_YELLOW);

            return ExitCode::CONFIG;
        }

        return null;
    }
}
