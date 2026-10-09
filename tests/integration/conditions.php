<?php
/**
 * CSR ticket condition rules, applied to real element queries.
 *
 *     docker exec -w /var/www/html ddev-plugin-testing-web php /var/www/craft-csr/tests/integration/conditions.php
 *
 * Each rule is built from config the way a saved custom source is, run through `modifyQuery()`
 * against tickets this script makes, and the result set compared with what `matchElement()` says
 * about the same tickets — the two must agree, or the index and anything that matches one element
 * at a time would disagree about the same source. Self-cleaning.
 */

$root = '/var/www/html';
require $root . '/bootstrap.php';

/** @var craft\console\Application $app */
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';

use craft\elements\User;
use justinholtweb\csr\elements\conditions\AssigneeConditionRule;
use justinholtweb\csr\elements\conditions\CategoryConditionRule;
use justinholtweb\csr\elements\conditions\PriorityConditionRule;
use justinholtweb\csr\elements\conditions\SlaBreachedConditionRule;
use justinholtweb\csr\elements\conditions\SlaDueWithinConditionRule;
use justinholtweb\csr\elements\conditions\SourceConditionRule;
use justinholtweb\csr\elements\conditions\StatusKindConditionRule;
use justinholtweb\csr\elements\conditions\TeamConditionRule;
use justinholtweb\csr\elements\conditions\TicketCondition;
use justinholtweb\csr\elements\conditions\TicketStatusConditionRule;
use justinholtweb\csr\elements\Ticket;
use justinholtweb\csr\enums\StatusKind;
use justinholtweb\csr\enums\TicketSource;
use justinholtweb\csr\Plugin;

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

$plugin = Plugin::getInstance();
$plugin->edition = Plugin::EDITION_PRO;
$settings = $plugin->getSettings();
$settings->notifyRequesterOnNew = false;
$settings->notifyAgentsOnNew = false;
$settings->queueNotifications = false;
$settings->slaEnabled = false;
$settings->autoAssign = 'none';

$prefix = 'cnd';
$elements = Craft::$app->getElements();

foreach (Ticket::find()->isSpam(null)->limit(null)->all() as $stale) {
    if (str_starts_with((string)$stale->title, $prefix)) {
        $elements->deleteElement($stale, true);
    }
}

$admin = User::find()->admin(true)->one();
$statuses = $plugin->statuses;
$open = $statuses->getFirstOfKind(StatusKind::Open);
$pending = $statuses->getFirstOfKind(StatusKind::Pending);
$resolved = $statuses->getFirstOfKind(StatusKind::Resolved);
$category = $plugin->categories->getTree()[0] ?? null;
$team = $plugin->teams->getAllTeams()[0] ?? null;

$make = static function(string $name, array $attributes) use ($plugin, $prefix): Ticket {
    $ticket = $plugin->tickets->submit([
        'email' => $name . '@conditions.example',
        'subject' => $prefix . ' ' . $name,
        'message' => 'Condition check',
        'source' => $attributes['source'] ?? TicketSource::Web,
        'requesterId' => null,
    ], false);

    foreach ($attributes as $key => $value) {
        if ($key !== 'source') {
            $ticket->$key = $value;
        }
    }

    Craft::$app->getElements()->saveElement($ticket, false);

    return $ticket;
};

$tickets = [
    'urgentLate' => $make('urgentlate', [
        'priority' => 'urgent', 'statusId' => $open->id, 'agentId' => $admin?->id,
        'firstResponseDue' => new DateTime('-2 hours'), 'categoryId' => $category?->id,
    ]),
    'highSoon' => $make('highsoon', [
        'priority' => 'high', 'statusId' => $open->id, 'teamId' => $team?->id,
        'firstResponseDue' => new DateTime('+90 minutes'), 'source' => TicketSource::Email,
    ]),
    'normalLater' => $make('normallater', [
        'priority' => 'normal', 'statusId' => $pending->id,
        'resolutionDue' => new DateTime('+10 hours'),
    ]),
    'lowDone' => $make('lowdone', [
        'priority' => 'low', 'statusId' => $resolved->id, 'agentId' => $admin?->id,
        'firstResponseBreached' => true, 'source' => TicketSource::Email,
    ]),
];

$names = array_flip(array_map(static fn(Ticket $t) => (int)$t->id, $tickets));

/** Apply a one-rule condition built from config; return [query result names, matchElement names]. */
$apply = static function(array $rule) use ($tickets, $names): array {
    $condition = Craft::$app->getConditions()->createCondition([
        'class' => TicketCondition::class,
        'elementType' => Ticket::class,
        'conditionRules' => [$rule],
    ]);

    $query = Ticket::find()->id(array_keys($names))->isSpam(null);
    $condition->modifyQuery($query);
    $fromQuery = array_map(static fn($id) => $names[(int)$id], $query->ids());
    sort($fromQuery);

    $fromMatch = [];

    foreach ($tickets as $name => $ticket) {
        $fresh = Ticket::find()->id($ticket->id)->isSpam(null)->one();

        if ($condition->matchElement($fresh)) {
            $fromMatch[] = $name;
        }
    }

    sort($fromMatch);

    return [$fromQuery, $fromMatch];
};

$expect = static function(array $rule, array $expected) use ($apply) {
    [$query, $match] = $apply($rule);
    sort($expected);

    if ($query !== $expected) {
        return 'query gave ' . json_encode($query) . ', expected ' . json_encode($expected);
    }

    return $match === $expected ?: 'matchElement gave ' . json_encode($match) . ' while the query gave ' . json_encode($query);
};

try {
    echo "\nThe condition\n";

    check('Ticket::createCondition() is a TicketCondition offering every CSR rule', function() {
        $condition = Ticket::createCondition();
        $offered = array_map(static fn($r) => is_string($r) ? $r : ($r['class'] ?? ''), (fn() => $this->selectableConditionRules())->call($condition));

        foreach ([PriorityConditionRule::class, StatusKindConditionRule::class, TicketStatusConditionRule::class, AssigneeConditionRule::class, TeamConditionRule::class, CategoryConditionRule::class, SourceConditionRule::class, SlaBreachedConditionRule::class, SlaDueWithinConditionRule::class] as $class) {
            if (!in_array($class, $offered, true)) {
                return "missing $class";
            }
        }

        return $condition instanceof TicketCondition;
    });

    check('a rule survives its config round trip (as a saved source does)', function() {
        $rule = Craft::$app->getConditions()->createConditionRule([
            'class' => SlaDueWithinConditionRule::class,
            'hours' => '6',
        ]);

        return $rule->getConfig()['hours'] === 6 && $rule->validate(['hours']) ?: json_encode($rule->getConfig());
    });

    check('every rule renders its inputs (the builder a custom source is edited in)', function() use ($admin) {
        Craft::$app->getUser()->setIdentity($admin);

        try {
            foreach ([PriorityConditionRule::class, StatusKindConditionRule::class, TicketStatusConditionRule::class, AssigneeConditionRule::class, TeamConditionRule::class, CategoryConditionRule::class, SourceConditionRule::class, SlaBreachedConditionRule::class, SlaDueWithinConditionRule::class] as $class) {
                $rule = Craft::$app->getConditions()->createConditionRule(['class' => $class]);
                $rule->setCondition(Ticket::createCondition());
                $html = $rule->getHtml();

                if (!str_contains($html, 'name=')) {
                    return "$class rendered no input";
                }
            }
        } finally {
            Craft::$app->getUser()->setIdentity(null);
        }

        return true;
    });

    echo "\nRules against real tickets\n";

    check('priority is one of urgent, high', fn() => $expect(['class' => PriorityConditionRule::class, 'operator' => 'in', 'values' => ['urgent', 'high']], ['urgentLate', 'highSoon']));
    check('priority is not one of urgent, high', fn() => $expect(['class' => PriorityConditionRule::class, 'operator' => 'ni', 'values' => ['urgent', 'high']], ['normalLater', 'lowDone']));
    check('status kind is pending', fn() => $expect(['class' => StatusKindConditionRule::class, 'operator' => 'in', 'values' => ['pending']], ['normalLater']));
    check('status kind is not resolved/closed', fn() => $expect(['class' => StatusKindConditionRule::class, 'operator' => 'ni', 'values' => ['resolved', 'closed']], ['urgentLate', 'highSoon', 'normalLater']));
    check('ticket status (by uid) is the open status', fn() => $expect(['class' => TicketStatusConditionRule::class, 'operator' => 'in', 'values' => [$open->uid]], ['urgentLate', 'highSoon']));
    check('a deleted status chosen in a saved source matches nothing, not everything', fn() => $expect(['class' => TicketStatusConditionRule::class, 'operator' => 'in', 'values' => ['00000000-0000-0000-0000-000000000000']], []));
    check('source is email', fn() => $expect(['class' => SourceConditionRule::class, 'operator' => 'in', 'values' => ['email']], ['highSoon', 'lowDone']));
    check('assigned to the admin', fn() => $expect(['class' => AssigneeConditionRule::class, 'operator' => 'in', 'values' => [(string)$admin->id]], ['urgentLate', 'lowDone']));
    check('unassigned', fn() => $expect(['class' => AssigneeConditionRule::class, 'operator' => 'in', 'values' => ['unassigned']], ['highSoon', 'normalLater']));
    check('“me” from a console run (nobody signed in) matches nobody’s tickets', fn() => $expect(['class' => AssigneeConditionRule::class, 'operator' => 'in', 'values' => ['me']], []));
    check('“me” resolves to whoever is signed in', function() use ($expect, $admin) {
        Craft::$app->getUser()->setIdentity($admin);

        try {
            return $expect(['class' => AssigneeConditionRule::class, 'operator' => 'in', 'values' => ['me']], ['urgentLate', 'lowDone']);
        } finally {
            Craft::$app->getUser()->setIdentity(null);
        }
    });
    check('category is the first category', fn() => $category === null ? true : $expect(['class' => CategoryConditionRule::class, 'operator' => 'in', 'values' => [(string)$category->id]], ['urgentLate']));
    check('team is the first team, or no team', fn() => $team === null ? true : $expect(['class' => TeamConditionRule::class, 'operator' => 'in', 'values' => ['none']], ['urgentLate', 'normalLater', 'lowDone']));
    check('past an SLA target (live comparison and the stamped column)', fn() => $expect(['class' => SlaBreachedConditionRule::class, 'value' => true], ['urgentLate', 'lowDone']));
    check('not past an SLA target', fn() => $expect(['class' => SlaBreachedConditionRule::class, 'value' => false], ['highSoon', 'normalLater']));
    check('SLA due within 2 hours (not yet late)', fn() => $expect(['class' => SlaDueWithinConditionRule::class, 'hours' => 2], ['highSoon']));
    check('SLA due within 12 hours', fn() => $expect(['class' => SlaDueWithinConditionRule::class, 'hours' => 12], ['highSoon', 'normalLater']));

    check('two rules together: urgent or high, and late or due within 2 hours — the “breaching soon” source', function() use ($tickets, $names) {
        $condition = Craft::$app->getConditions()->createCondition([
            'class' => TicketCondition::class,
            'elementType' => Ticket::class,
            'conditionRules' => [
                ['class' => PriorityConditionRule::class, 'operator' => 'in', 'values' => ['urgent', 'high']],
                ['class' => SlaDueWithinConditionRule::class, 'hours' => 2],
            ],
        ]);
        $query = Ticket::find()->id(array_keys($names))->isSpam(null);
        $condition->modifyQuery($query);
        $found = array_map(static fn($id) => $names[(int)$id], $query->ids());

        return $found === ['highSoon'] ?: json_encode($found);
    });
} finally {
    foreach ($tickets as $ticket) {
        $elements->deleteElement($ticket, true);
    }
}

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
