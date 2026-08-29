<?php

declare(strict_types=1);

namespace justinholtweb\csr\console\controllers;

use craft\console\Controller;
use craft\helpers\Console;
use justinholtweb\csr\elements\Ticket;
use justinholtweb\csr\Plugin;
use yii\console\ExitCode;

/**
 * `csr/tickets` — housekeeping the desk should not have to remember.
 *
 * Also run from Craft's garbage collection, so a site with no cron still gets it eventually. These
 * exist so it can be scheduled properly, and so somebody can watch it happen before trusting it
 * with a retention policy.
 */
class TicketsController extends Controller
{
    public $defaultAction = 'close-resolved';

    /** Show what would happen without doing it. */
    public bool $dryRun = false;

    /** Override the setting, in days. */
    public ?int $days = null;

    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), ['dryRun', 'days']);
    }

    /**
     * Close tickets that were resolved a while ago and nobody argued with.
     */
    public function actionCloseResolved(): int
    {
        $plugin = Plugin::getInstance();
        $days = $this->days ?? $plugin->getSettings()->closeResolvedAfterDays;

        if ($days <= 0) {
            $this->stdout("Automatic closing is switched off.\n", Console::FG_YELLOW);

            return ExitCode::OK;
        }

        if ($this->dryRun) {
            $count = (int)Ticket::find()
                ->statusKind(\justinholtweb\csr\enums\StatusKind::Resolved)
                ->count();

            $this->stdout("Would look at {$count} resolved tickets older than {$days} days.\n");

            return ExitCode::OK;
        }

        $closed = $plugin->tickets->closeResolved($days);
        $this->stdout("Closed {$closed} resolved tickets.\n", Console::FG_GREEN);

        return ExitCode::OK;
    }

    /**
     * Delete tickets past the retention period.
     *
     * A hard delete, because retention exists so that somebody can say the data is gone, and a
     * ticket sitting in the trash is not gone.
     */
    public function actionPrune(): int
    {
        $plugin = Plugin::getInstance();
        $days = $this->days ?? $plugin->getSettings()->ticketRetentionDays;

        if ($days <= 0) {
            $this->stdout("No retention period is set, so nothing is deleted.\n", Console::FG_YELLOW);

            return ExitCode::OK;
        }

        if (!$this->dryRun && $this->interactive && !$this->confirm("Permanently delete resolved and closed tickets older than {$days} days?")) {
            return ExitCode::OK;
        }

        if ($this->dryRun) {
            $this->stdout("Dry run: nothing was deleted.\n");

            return ExitCode::OK;
        }

        $deleted = $plugin->tickets->prune($days);
        $this->stdout("Deleted {$deleted} tickets.\n", Console::FG_GREEN);

        return ExitCode::OK;
    }

    /**
     * Recount the reply counters on every ticket.
     *
     * Only needed after a restore or a manual delete. The counters are maintained as replies are
     * posted; this is the "prove it" command.
     */
    public function actionRecount(): int
    {
        $plugin = Plugin::getInstance();
        $ids = Ticket::find()->isSpam(null)->limit(null)->ids();

        Console::startProgress(0, count($ids));

        foreach ($ids as $index => $id) {
            $plugin->tickets->recountReplies((int)$id);
            Console::updateProgress($index + 1, count($ids));
        }

        Console::endProgress();
        $this->stdout('Recounted ' . count($ids) . " tickets.\n", Console::FG_GREEN);

        return ExitCode::OK;
    }
}
