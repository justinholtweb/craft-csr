<?php
/**
 * CSR's console equivalents of the control panel's bulk actions — run as real commands.
 *
 *     docker exec -w /var/www/html ddev-plugin-testing-web php /var/www/craft-csr/tests/integration/console.php
 *
 * Each command is executed with `php craft …` in its own process, against tickets this script
 * makes, and the database is read afterwards: the point is that the terminal goes through the
 * same service methods the control panel does, so the timeline, the stamps and the edition rules
 * come out the same. Self-cleaning; queued notification jobs are removed.
 */

$root = '/var/www/html';
require $root . '/bootstrap.php';

/** @var craft\console\Application $app */
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';

use craft\db\Query;
use craft\elements\User;
use justinholtweb\csr\elements\Ticket;
use justinholtweb\csr\enums\StatusKind;
use justinholtweb\csr\enums\TicketSource;
use justinholtweb\csr\Plugin;
use justinholtweb\csr\records\Table;

$passed = 0;
$failed = 0;

function check(string $label, callable $test): void
{
    global $passed, $failed;

    try {
        $result = $test();
    } catch (Throwable $e) {
        $result = get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine();
    }

    if ($result === true) {
        $passed++;
        echo "  ✓ $label\n";
    } else {
        $failed++;
        echo "  ✗ $label\n    " . (is_string($result) ? $result : var_export($result, true)) . "\n";
    }
}

/** @return array{0: int, 1: string} */
function craft(string $command): array
{
    exec('cd /var/www/html && php craft ' . $command . ' --interactive=0 2>&1', $output, $code);

    return [$code, implode("\n", $output)];
}

function reload(Ticket $ticket): Ticket
{
    return Ticket::find()->id($ticket->id)->isSpam(null)->trashed(null)->one();
}

$plugin = Plugin::getInstance();

if (!$plugin->isPro()) {
    echo "CSR must be on Pro in the harness.\n";
    exit(1);
}

$settings = $plugin->getSettings();
$settings->notifyRequesterOnNew = false;
$settings->notifyAgentsOnNew = false;
$settings->queueNotifications = false;
$settings->autoAssign = 'none';

$queueFloor = (int)(new Query())->from('{{%queue}}')->max('id');
$prefix = 'con';
$elements = Craft::$app->getElements();

foreach (Ticket::find()->isSpam(null)->trashed(null)->limit(null)->all() as $stale) {
    if (str_starts_with((string)$stale->title, $prefix . ' ')) {
        $elements->deleteElement($stale, true);
    }
}

$make = static function(string $name) use ($plugin, $prefix): Ticket {
    return $plugin->tickets->submit([
        'email' => $name . '@console.example',
        'subject' => "$prefix $name",
        'message' => 'Console check',
        'source' => TicketSource::ControlPanel,
        'requesterId' => null,
    ], false);
};

$a = $make('alpha');
$b = $make('bravo');
$c = $make('charlie');
$resolved = $plugin->statuses->getFirstOfKind(StatusKind::Resolved);
$admin = User::find()->admin(true)->one();
$category = $plugin->categories->getTree()[0] ?? null;
$team = $plugin->teams->getAllTeams()[0] ?? null;
$csv = Craft::$app->getPath()->getTempPath() . '/csr-console-export.csv';

try {
    echo "\nSelections\n";

    check('a bulk action with no selection refuses to run (exit 64), and says how to choose', function() {
        [$code, $out] = craft('csr/tickets/set-priority high');

        return $code === 64 && str_contains($out, '--numbers') ?: "exit $code: $out";
    });

    check('--dry-run changes nothing', function() use ($a) {
        [$code, $out] = craft("csr/tickets/set-priority urgent --ids={$a->id} --dry-run");

        return $code === 0 && str_contains($out, 'would affect') && reload($a)->priority !== 'urgent' ?: "exit $code: $out";
    });

    echo "\nThe element index's Set status, and the ticket sidebar\n";

    check('set-status by ticket number goes through setStatus(): resolution stamped, timeline written', function() use ($a, $b, $resolved) {
        [$code, $out] = craft("csr/tickets/set-status {$resolved->handle} --numbers={$a->number},{$b->number} --silent");
        $ra = reload($a);
        $event = (new Query())->from([Table::EVENTS])->where(['ticketId' => $a->id, 'type' => justinholtweb\csr\enums\EventType::StatusChanged->value])->exists();

        return $code === 0 && $ra->statusId === $resolved->id && $ra->resolvedAt !== null && reload($b)->statusId === $resolved->id && $event
            ?: "exit $code: $out";
    });

    check('an unknown status handle is a data error (65), and nothing moves', function() use ($c) {
        $before = reload($c)->statusId;
        [$code] = craft("csr/tickets/set-status no-such-status --ids={$c->id}");

        return $code === 65 && reload($c)->statusId === $before ?: "exit $code";
    });

    check('--kind selects by status kind: the resolved ones only', function() use ($c) {
        [$code, $out] = craft("csr/tickets/set-priority high --kind=resolved --ids={$c->id}");

        // c is not resolved, so the intersection is empty.
        return $code === 0 && reload($c)->priority !== 'high' ?: "exit $code: $out";
    });

    check('set-priority', function() use ($c) {
        [$code, $out] = craft("csr/tickets/set-priority urgent --ids={$c->id}");

        return $code === 0 && reload($c)->priority === 'urgent' ?: "exit $code: $out";
    });

    check('an invalid priority is a usage error', function() use ($c) {
        [$code] = craft("csr/tickets/set-priority whenever --ids={$c->id}");

        return $code === 64 ?: "exit $code";
    });

    check('assign to a user by username, then unassign', function() use ($c, $admin) {
        [$code1] = craft("csr/tickets/assign {$admin->username} --ids={$c->id} --silent");
        $assigned = reload($c)->agentId === (int)$admin->id;
        [$code2] = craft("csr/tickets/assign none --ids={$c->id}");

        return $code1 === 0 && $assigned && $code2 === 0 && reload($c)->agentId === null ?: "exit $code1/$code2";
    });

    check('assigning to somebody who cannot answer tickets is refused', function() use ($c) {
        $nobody = User::find()->admin(false)->status(null)->one();

        if ($nobody === null || $nobody->can(Plugin::PERMISSION_MANAGE_TICKETS)) {
            return true;
        }

        [$code] = craft("csr/tickets/assign {$nobody->id} --ids={$c->id}");

        return $code === 65 && reload($c)->agentId === null ?: "exit $code";
    });

    check('set-category, and back to none', function() use ($c, $category) {
        if ($category === null) {
            return true;
        }

        [$code1] = craft("csr/tickets/set-category {$category->handle} --ids={$c->id}");
        $filed = reload($c)->categoryId === (int)$category->id;
        [$code2] = craft("csr/tickets/set-category none --ids={$c->id}");

        return $code1 === 0 && $filed && $code2 === 0 && reload($c)->categoryId === null ?: "exit $code1/$code2";
    });

    check('set-team (Pro)', function() use ($c, $team) {
        if ($team === null) {
            return true;
        }

        [$code, $out] = craft("csr/tickets/set-team {$team->handle} --ids={$c->id}");

        return $code === 0 && reload($c)->teamId === (int)$team->id ?: "exit $code: $out";
    });

    echo "\nSpam, merge, export, delete\n";

    check('mark-spam, and --undo', function() use ($c) {
        [$code1] = craft("csr/tickets/mark-spam --ids={$c->id}");
        $spam = reload($c)->isSpam;
        [$code2] = craft("csr/tickets/mark-spam --undo --ids={$c->id}");

        return $code1 === 0 && $spam && $code2 === 0 && !reload($c)->isSpam ?: "exit $code1/$code2";
    });

    check('merge folds one ticket into another, through Tickets::merge()', function() use ($b, $c) {
        [$code, $out] = craft("csr/tickets/merge {$b->number} {$c->number}");

        return $code === 0 && reload($b)->mergedIntoId === (int)$c->id ?: "exit $code: $out";
    });

    check('export writes the same CSV the control panel streams', function() use ($a, $c, $csv) {
        [$code, $out] = craft("csr/tickets/export $csv --ids={$a->id},{$c->id}");
        $rows = is_file($csv) ? array_map('str_getcsv', file($csv, FILE_IGNORE_NEW_LINES)) : [];

        return $code === 0 && ($rows[0][0] ?? '') === 'Number' && count($rows) === 3
            && in_array((string)$a->number, array_column($rows, 0), true)
            ?: "exit $code: $out / " . count($rows) . ' rows';
    });

    check('kb/export-searches writes a CSV', function() use ($csv) {
        @unlink($csv);
        [$code, $out] = craft("csr/kb/export-searches $csv --days=7");

        return $code === 0 && str_starts_with((string)@file_get_contents($csv), 'Search,') ?: "exit $code: $out";
    });

    check('delete sends to the trash; --hard removes for good', function() use ($a, $c) {
        [$code1] = craft("csr/tickets/delete --ids={$a->id}");
        $trashed = Ticket::find()->id($a->id)->isSpam(null)->trashed(true)->exists();
        [$code2] = craft("csr/tickets/delete --ids={$c->id} --hard");
        $gone = !Ticket::find()->id($c->id)->isSpam(null)->trashed(null)->exists();

        return $code1 === 0 && $trashed && $code2 === 0 && $gone ?: "exit $code1/$code2";
    });

    check('every CP bulk action has a console command (the audit, kept honest)', function() {
        $controller = new justinholtweb\csr\console\controllers\TicketsController('tickets', Craft::$app);
        $actions = array_map(static fn($m) => $m->name, (new ReflectionClass($controller))->getMethods(ReflectionMethod::IS_PUBLIC));

        foreach (['actionSetStatus', 'actionSetPriority', 'actionAssign', 'actionSetTeam', 'actionSetCategory', 'actionMarkSpam', 'actionMerge', 'actionDelete', 'actionExport', 'actionCloseResolved', 'actionPrune'] as $action) {
            if (!in_array($action, $actions, true)) {
                return "missing $action";
            }
        }

        return true;
    });
} finally {
    foreach ([$a, $b, $c] as $ticket) {
        $found = Ticket::find()->id($ticket->id)->isSpam(null)->trashed(null)->one();

        if ($found !== null) {
            $elements->deleteElement($found, true);
        }
    }

    @unlink($csv);
    Craft::$app->getDb()->createCommand()->delete('{{%queue}}', ['>', 'id', $queueFloor])->execute();
}

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
