<?php

declare(strict_types=1);

namespace justinholtweb\csr\console\controllers;

use Craft;
use craft\console\Controller;
use craft\elements\User;
use craft\helpers\Console;
use DateTime;
use justinholtweb\csr\elements\db\TicketQuery;
use justinholtweb\csr\elements\Ticket;
use justinholtweb\csr\enums\Priority;
use justinholtweb\csr\enums\StatusKind;
use justinholtweb\csr\models\Edition;
use justinholtweb\csr\Plugin;
use yii\console\ExitCode;

/**
 * `csr/tickets` — housekeeping the desk should not have to remember, and every bulk action the
 * control panel has.
 *
 * The housekeeping (`close-resolved`, `prune`, `recount`) also runs from Craft's garbage
 * collection, so a site with no cron still gets it eventually.
 *
 * The bulk actions go through exactly the service methods the control panel calls — the Set status
 * element action, the ticket screen's sidebar, Merge, Mark as spam, Delete, Export — so the SLA
 * clock, the timeline, the notifications and the edition rules behave identically from a terminal.
 * Each takes a selection, and refuses to run without one:
 *
 *     php craft csr/tickets/set-status resolved --numbers=1041,1042
 *     php craft csr/tickets/assign jo@example.com --kind=new
 *     php craft csr/tickets/set-priority urgent --ids=8812 --dry-run
 *     php craft csr/tickets/export tickets.csv --days=30
 */
class TicketsController extends Controller
{
    public $defaultAction = 'close-resolved';

    /** Show what would happen without doing it. */
    public bool $dryRun = false;

    /** Override the setting, in days. */
    public ?int $days = null;

    /** Tickets by element ID, comma-separated. */
    public ?string $ids = null;

    /** Tickets by ticket number, comma-separated. */
    public ?string $numbers = null;

    /** Tickets in this status, by handle. */
    public ?string $status = null;

    /** Tickets whose status is of this kind: new, open, pending, resolved, closed. */
    public ?string $kind = null;

    /** Include tickets marked as spam in the selection. */
    public bool $includeSpam = false;

    /** Don't email anybody about the change (status letters, assignment notices). */
    public bool $silent = false;

    /** For `delete`: skip the trash. */
    public bool $hard = false;

    /** For `mark-spam`: take the mark off instead. */
    public bool $undo = false;

    public function options($actionID): array
    {
        $options = array_merge(parent::options($actionID), ['dryRun', 'days']);

        if (in_array($actionID, ['set-status', 'set-priority', 'assign', 'set-team', 'set-category', 'mark-spam', 'delete', 'export'], true)) {
            array_push($options, 'ids', 'numbers', 'status', 'kind', 'includeSpam');
        }

        if (in_array($actionID, ['set-status', 'assign'], true)) {
            $options[] = 'silent';
        }

        if ($actionID === 'delete') {
            $options[] = 'hard';
        }

        if ($actionID === 'mark-spam') {
            $options[] = 'undo';
        }

        return $options;
    }

    // Bulk actions
    // -------------------------------------------------------------------------

    /**
     * Move tickets to a status — the element index's "Set status" action.
     *
     * @param string $handle The status handle.
     */
    public function actionSetStatus(string $handle): int
    {
        $plugin = Plugin::getInstance();
        $status = $plugin->statuses->getStatusByHandle($handle);

        if ($status === null) {
            $this->stderr("There is no status with the handle “{$handle}”.\n", Console::FG_RED);

            return ExitCode::DATAERR;
        }

        return $this->each('Move to ' . $status->name, fn(Ticket $ticket) => $ticket->statusId === $status->id
            ? null
            : $plugin->tickets->setStatus($ticket, (int)$status->id, true, !$this->silent));
    }

    /**
     * Change tickets' priority — the ticket sidebar's Priority.
     *
     * @param string $priority low, normal, high or urgent.
     */
    public function actionSetPriority(string $priority): int
    {
        if (Priority::tryFrom($priority) === null) {
            $this->stderr('Priority must be one of: ' . implode(', ', array_column(Priority::cases(), 'value')) . ".\n", Console::FG_RED);

            return ExitCode::USAGE;
        }

        $tickets = Plugin::getInstance()->tickets;

        return $this->each("Set priority to $priority", fn(Ticket $ticket) => $ticket->priority === $priority
            ? null
            : $tickets->setPriority($ticket, $priority));
    }

    /**
     * Assign tickets — the sidebar's Agent, and Claim.
     *
     * @param string $agent A user ID, username or email, or `none` to unassign.
     */
    public function actionAssign(string $agent): int
    {
        $agentId = null;

        if ($agent !== 'none') {
            $user = is_numeric($agent)
                ? User::find()->id((int)$agent)->status(null)->one()
                : User::find()->username($agent)->status(null)->one() ?? User::find()->email($agent)->status(null)->one();

            if (!$user instanceof User) {
                $this->stderr("There is no user “{$agent}”.\n", Console::FG_RED);

                return ExitCode::DATAERR;
            }

            // The same rule the control panel's agent list applies.
            if (!$user->can(Plugin::PERMISSION_MANAGE_TICKETS)) {
                $this->stderr("{$user->username} cannot answer tickets, so tickets cannot be assigned to them.\n", Console::FG_RED);

                return ExitCode::DATAERR;
            }

            $agentId = (int)$user->id;
        }

        $tickets = Plugin::getInstance()->tickets;

        return $this->each($agentId !== null ? "Assign to $agent" : 'Unassign', function(Ticket $ticket) use ($tickets, $agentId) {
            if ($ticket->agentId === $agentId) {
                return null;
            }

            if ($this->silent) {
                $ticket->agentId = $agentId;

                return Craft::$app->getElements()->saveElement($ticket, false);
            }

            return $tickets->assign($ticket, $agentId);
        });
    }

    /**
     * Route tickets to a team (Pro).
     *
     * @param string $team A team handle, or `none`.
     */
    public function actionSetTeam(string $team): int
    {
        $plugin = Plugin::getInstance();

        if (!Edition::allowsTeams($plugin->isPro())) {
            $this->stderr("Teams are a Pro feature.\n", Console::FG_YELLOW);

            return ExitCode::OK;
        }

        $teamId = null;

        if ($team !== 'none') {
            $teamId = $plugin->teams->getTeamByHandle($team)?->id;

            if ($teamId === null) {
                $this->stderr("There is no team with the handle “{$team}”.\n", Console::FG_RED);

                return ExitCode::DATAERR;
            }
        }

        return $this->each($teamId !== null ? "Route to $team" : 'Remove the team', fn(Ticket $ticket) => $ticket->teamId === $teamId
            ? null
            : $plugin->tickets->setTeam($ticket, $teamId !== null ? (int)$teamId : null));
    }

    /**
     * File tickets under a category.
     *
     * @param string $category A category handle, or `none`.
     */
    public function actionSetCategory(string $category): int
    {
        $plugin = Plugin::getInstance();
        $categoryId = null;

        if ($category !== 'none') {
            $categoryId = $plugin->categories->getCategoryByHandle($category)?->id;

            if ($categoryId === null) {
                $this->stderr("There is no category with the handle “{$category}”.\n", Console::FG_RED);

                return ExitCode::DATAERR;
            }
        }

        return $this->each($categoryId !== null ? "File under $category" : 'Uncategorise', fn(Ticket $ticket) => $ticket->categoryId === $categoryId
            ? null
            : $plugin->tickets->setCategory($ticket, $categoryId !== null ? (int)$categoryId : null));
    }

    /** Mark tickets as spam, or with `--undo` take the mark off. */
    public function actionMarkSpam(): int
    {
        $this->includeSpam = $this->includeSpam || $this->undo;
        $tickets = Plugin::getInstance()->tickets;
        $mark = !$this->undo;

        return $this->each($mark ? 'Mark as spam' : 'Unmark as spam', fn(Ticket $ticket) => $ticket->isSpam === $mark
            ? null
            : $tickets->markAsSpam($ticket, $mark));
    }

    /**
     * Fold one ticket into another (Pro) — the ticket screen's Merge.
     *
     * @param int $from The number of the duplicate.
     * @param int $into The number of the ticket that will be answered.
     */
    public function actionMerge(int $from, int $into): int
    {
        $plugin = Plugin::getInstance();

        if (!Edition::allowsMerge($plugin->isPro())) {
            $this->stderr("Merging tickets is a Pro feature.\n", Console::FG_YELLOW);

            return ExitCode::OK;
        }

        $source = $plugin->tickets->getTicketByNumber($from);
        $target = $plugin->tickets->getTicketByNumber($into);

        if ($source === null || $target === null || $source->id === $target->id) {
            $this->stderr("Both tickets must exist and be different.\n", Console::FG_RED);

            return ExitCode::DATAERR;
        }

        if ($this->dryRun) {
            $this->stdout("Would merge #{$from} into #{$into}.\n");

            return ExitCode::OK;
        }

        if (!$plugin->tickets->merge($source, $target)) {
            $this->stderr("Couldn’t merge those tickets; see the log.\n", Console::FG_RED);

            return ExitCode::UNSPECIFIED_ERROR;
        }

        $this->stdout("Merged #{$from} into #{$into}.\n", Console::FG_GREEN);

        return ExitCode::OK;
    }

    /** Delete tickets — to the trash, or with `--hard` for good. */
    public function actionDelete(): int
    {
        $query = $this->selection();

        if ($query === null) {
            return ExitCode::USAGE;
        }

        $count = (int)$query->count();

        if (!$this->dryRun && $this->interactive && !$this->confirm(($this->hard ? 'Permanently delete' : 'Delete') . " {$count} tickets?")) {
            return ExitCode::OK;
        }

        return $this->each($this->hard ? 'Delete for good' : 'Delete', fn(Ticket $ticket) => Craft::$app->getElements()->deleteElement($ticket, $this->hard), $query);
    }

    /**
     * Export tickets as CSV (Pro) — the tickets screen's Export. With no selection, every ticket;
     * `--days` limits it to those raised recently.
     *
     * @param string $file Where to write the CSV.
     */
    public function actionExport(string $file): int
    {
        $plugin = Plugin::getInstance();

        if (!$plugin->exporter->isAvailable()) {
            $this->stderr("Exporting is a Pro feature.\n", Console::FG_YELLOW);

            return ExitCode::OK;
        }

        $query = $this->hasSelection() ? $this->selection() : Ticket::find()->isSpam(null)->limit(null);

        if ($query === null) {
            return ExitCode::USAGE;
        }

        if ($this->days !== null && $this->days > 0) {
            $query->submittedAfter((new DateTime())->modify("-{$this->days} days"));
        }

        $plugin->exporter->writeTickets($query, $file);
        $this->stdout('Wrote ' . (int)$query->count() . " tickets to {$file}.\n", Console::FG_GREEN);

        return ExitCode::OK;
    }

    private function hasSelection(): bool
    {
        return $this->ids !== null || $this->numbers !== null || $this->status !== null || $this->kind !== null;
    }

    /** The tickets the selection options name, or null (with a message) when there are none. */
    private function selection(): ?TicketQuery
    {
        if (!$this->hasSelection()) {
            $this->stderr("Choose the tickets: --ids, --numbers, --status or --kind.\n", Console::FG_RED);

            return null;
        }

        $query = Ticket::find()->limit(null)->isSpam($this->includeSpam ? null : false);
        $list = static fn(string $value) => array_values(array_filter(array_map('intval', explode(',', $value))));

        if ($this->ids !== null) {
            $query->id($list($this->ids) ?: [0]);
        }

        if ($this->numbers !== null) {
            $query->number($list($this->numbers) ?: [0]);
        }

        if ($this->status !== null) {
            $status = Plugin::getInstance()->statuses->getStatusByHandle($this->status);
            $query->statusId($status !== null ? (int)$status->id : 0);
        }

        if ($this->kind !== null) {
            $kind = StatusKind::tryFrom($this->kind);

            if ($kind === null) {
                $this->stderr('--kind must be one of: ' . implode(', ', array_column(StatusKind::cases(), 'value')) . ".\n", Console::FG_RED);

                return null;
            }

            $query->statusKind($kind);
        }

        return $query;
    }

    /**
     * Apply a change to every selected ticket, one at a time, through the service.
     *
     * @param callable(Ticket): (bool|null) $change Null means "already like that".
     */
    private function each(string $label, callable $change, ?TicketQuery $query = null): int
    {
        $query ??= $this->selection();

        if ($query === null) {
            return ExitCode::USAGE;
        }

        $total = (int)$query->count();

        if ($this->dryRun) {
            $this->stdout("{$label}: would affect up to {$total} tickets.\n");

            return ExitCode::OK;
        }

        $changed = 0;
        $unchanged = 0;
        $failed = 0;

        foreach ($query->ids() as $id) {
            /** @var Ticket|null $ticket */
            $ticket = Ticket::find()->id($id)->isSpam(null)->one();

            if ($ticket === null) {
                continue;
            }

            $result = $change($ticket);

            match ($result) {
                null => $unchanged++,
                true => $changed++,
                default => $failed++,
            };
        }

        $this->stdout("{$label}: {$changed} changed, {$unchanged} already were, {$failed} failed.\n", $failed > 0 ? Console::FG_YELLOW : Console::FG_GREEN);

        return $failed > 0 ? ExitCode::UNSPECIFIED_ERROR : ExitCode::OK;
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
