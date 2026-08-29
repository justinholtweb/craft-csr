<?php

declare(strict_types=1);

namespace justinholtweb\csr\console\controllers;

use craft\console\Controller;
use craft\helpers\Console;
use justinholtweb\csr\Plugin;
use yii\console\ExitCode;

/**
 * `csr/sla` — the SLA sweep, for cron.
 *
 * A ticket turns red in the control panel the moment its deadline passes, because
 * {@see \justinholtweb\csr\elements\Ticket::getIsBreaching()} compares at read time. What this
 * command adds is the part nobody is looking at the screen for: stamping the breach so the reports
 * can count it, emailing the desk, and escalating.
 *
 * Every five minutes is sensible. The sweep is idempotent — the stamped columns are what stop a
 * ticket being reported twice — so running it more often costs queries and nothing else.
 */
class SlaController extends Controller
{
    public $defaultAction = 'check';

    /** How many tickets to look at in one run. */
    public ?int $limit = 500;

    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), ['limit']);
    }

    /**
     * Find tickets past their targets, stamp them, notify, and escalate.
     */
    public function actionCheck(): int
    {
        $plugin = Plugin::getInstance();

        if (!$plugin->getSettings()->slaEnabled) {
            $this->stdout("Service level agreements are switched off in the CSR settings.\n", Console::FG_YELLOW);

            return ExitCode::OK;
        }

        $counts = $plugin->slas->sweep($this->limit);

        $this->stdout(sprintf(
            "First-response breaches: %d\nResolution breaches: %d\nEscalated: %d\n",
            $counts['firstResponse'],
            $counts['resolution'],
            $counts['escalated'],
        ));

        return ExitCode::OK;
    }

    /**
     * Show what a target set now would be due at, so opening hours can be sanity-checked.
     *
     * @param int $minutes The target, in minutes.
     */
    public function actionPreview(int $minutes = 240): int
    {
        $plugin = Plugin::getInstance();

        $withHours = $plugin->slas->preview($minutes, true);
        $withoutHours = $plugin->slas->preview($minutes, false);

        $this->stdout(sprintf(
            "A %d-minute target set now is due:\n  during opening hours: %s\n  around the clock:     %s\n",
            $minutes,
            $withHours->format('D j M Y, H:i'),
            $withoutHours->format('D j M Y, H:i'),
        ));

        return ExitCode::OK;
    }
}
