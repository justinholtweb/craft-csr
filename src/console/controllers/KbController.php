<?php

declare(strict_types=1);

namespace justinholtweb\csr\console\controllers;

use craft\console\Controller;
use craft\helpers\Console;
use justinholtweb\csr\Plugin;
use yii\console\ExitCode;

/**
 * `csr/kb` — the knowledge base's own housekeeping, and the report worth having in a terminal.
 */
class KbController extends Controller
{
    public $defaultAction = 'gaps';

    /**
     * The window, in days. Unset, each action uses its own default: 30 for `gaps`, and the
     * configured search retention for `prune-searches` — a default of 30 there deleted eleven
     * months of history on a site that had asked to keep a year.
     */
    public ?int $days = null;

    /** How many rows to show. */
    public int $limit = 20;

    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), ['days', 'limit']);
    }

    /**
     * What people searched for and did not find.
     *
     * The list of articles the knowledge base is missing, in the order they are worth writing.
     * Here as well as in the control panel because this is the report somebody wants to paste into
     * a planning document, and because a terminal is where a lot of documentation gets planned.
     */
    public function actionGaps(): int
    {
        $plugin = Plugin::getInstance();
        $this->days ??= 30;

        if (!$plugin->isPro()) {
            $this->stdout("Failed-search analytics are a Pro feature.\n", Console::FG_YELLOW);

            return ExitCode::OK;
        }

        $failed = $plugin->analytics->getFailedSearches($this->days, $this->limit);

        if ($failed === []) {
            $this->stdout("No failed searches in the last {$this->days} days.\n", Console::FG_GREEN);

            return ExitCode::OK;
        }

        $this->stdout("Searches that came back with nothing, last {$this->days} days:\n\n", Console::FG_CYAN);

        foreach ($failed as $row) {
            $this->stdout(sprintf(
                "  %-40s %4d searches, %d with no results\n",
                mb_strimwidth((string)$row['query'], 0, 38, '…'),
                (int)$row['searches'],
                (int)$row['noResults'],
            ));
        }

        $unclicked = $plugin->analytics->getUnclickedSearches($this->days, $this->limit);

        if ($unclicked !== []) {
            $this->stdout("\nSearches that found something and were abandoned anyway:\n", Console::FG_CYAN);
            $this->stdout("(usually a title problem, not a missing article)\n\n", Console::FG_GREY);

            foreach ($unclicked as $row) {
                $this->stdout(sprintf("  %-40s %4d searches\n", mb_strimwidth((string)$row['query'], 0, 38, '…'), (int)$row['searches']));
            }
        }

        $this->stdout("\n");

        return ExitCode::OK;
    }

    /**
     * Recount the helpful and unhelpful totals from the rating rows.
     */
    public function actionRecountRatings(): int
    {
        $count = Plugin::getInstance()->articles->recountRatings();
        $this->stdout("Recounted ratings on {$count} articles.\n", Console::FG_GREEN);

        return ExitCode::OK;
    }

    /**
     * Delete search rows past the retention window — the configured one, unless `--days` says
     * otherwise.
     */
    public function actionPruneSearches(): int
    {
        $deleted = Plugin::getInstance()->search->prune($this->days !== null && $this->days > 0 ? $this->days : null);
        $this->stdout("Deleted {$deleted} search rows.\n", Console::FG_GREEN);

        return ExitCode::OK;
    }

    /**
     * Every search in the window as CSV (Pro) — the Searches report's Export.
     *
     * @param string $file Where to write the CSV.
     */
    public function actionExportSearches(string $file): int
    {
        $plugin = Plugin::getInstance();

        if (!$plugin->exporter->isAvailable()) {
            $this->stdout("Exporting is a Pro feature.\n", Console::FG_YELLOW);

            return ExitCode::OK;
        }

        $plugin->exporter->writeSearches($file, $this->days ?? 90);
        $this->stdout("Wrote the searches to {$file}.\n", Console::FG_GREEN);

        return ExitCode::OK;
    }

    /**
     * The "was this helpful?" comments as CSV (Pro) — the Articles report's Export.
     *
     * @param string $file Where to write the CSV.
     */
    public function actionExportFeedback(string $file): int
    {
        $plugin = Plugin::getInstance();

        if (!$plugin->exporter->isAvailable()) {
            $this->stdout("Exporting is a Pro feature.\n", Console::FG_YELLOW);

            return ExitCode::OK;
        }

        $plugin->exporter->writeFeedback($file, $this->days ?? 90);
        $this->stdout("Wrote the feedback to {$file}.\n", Console::FG_GREEN);

        return ExitCode::OK;
    }
}
