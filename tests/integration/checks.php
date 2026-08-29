<?php
/**
 * CSR integration checks.
 *
 * Run inside the plugin-testing container, from the site root:
 *
 *     docker exec -w /var/www/html ddev-plugin-testing-web php /var/www/craft-csr/tests/integration/checks.php
 *
 * Covers what a unit fixture cannot: real element saves through Craft, the visibility gate as SQL
 * against the actual database, a ticket submitted through the same service the anonymous
 * controller uses, the SLA arithmetic against real opening hours, and the edition boundary.
 *
 * Idempotent and self-cleaning: every run builds its own categories, articles and tickets, prefixes
 * them so a crashed earlier run can be swept up, and removes them at the end.
 *
 * Nothing here sends an email or contacts a CAPTCHA provider — notifications are switched off
 * through the settings model rather than by mocking the mailer, so the code takes exactly the
 * branch it takes in production on a site that has them off. Settings and the edition are changed
 * **in memory only**: the harness's project config is contended, and persisting from a long script
 * produces StaleResourceException in code that has nothing to do with the test.
 */

$root = '/var/www/html';
require $root . '/bootstrap.php';

/** @var craft\console\Application $app */
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';

use craft\elements\User;
use craft\helpers\Db;
use justinholtweb\csr\elements\Article;
use justinholtweb\csr\elements\Ticket;
use justinholtweb\csr\enums\DeflectionOutcome;
use justinholtweb\csr\enums\FieldType;
use justinholtweb\csr\enums\Priority;
use justinholtweb\csr\enums\StatusKind;
use justinholtweb\csr\enums\TicketSource;
use justinholtweb\csr\enums\Visibility;
use justinholtweb\csr\models\Category;
use justinholtweb\csr\models\Edition;
use justinholtweb\csr\models\FormField;
use justinholtweb\csr\models\Settings;
use justinholtweb\csr\models\SlaPolicy;
use justinholtweb\csr\models\Status;
use justinholtweb\csr\Plugin;
use justinholtweb\csr\records\Table;

$passed = 0;
$failed = 0;
$prefix = 'chk';

function check(string $label, callable $test): void
{
    global $passed, $failed;

    try {
        $result = $test();

        if ($result === true) {
            $passed++;
            echo "  ✓ $label\n";

            return;
        }

        $failed++;
        echo "  ✗ $label\n    " . (is_string($result) ? $result : 'returned ' . var_export($result, true)) . "\n";
    } catch (Throwable $e) {
        $failed++;
        echo "  ✗ $label\n    " . get_class($e) . ': ' . $e->getMessage() . "\n    " . $e->getFile() . ':' . $e->getLine() . "\n";
    }
}

function section(string $title): void
{
    echo "\n$title\n";
}

$plugin = Plugin::getInstance();

if ($plugin === null) {
    echo "CSR is not installed in this environment.\n";
    exit(1);
}

$elements = Craft::$app->getElements();
$siteId = (int)Craft::$app->getSites()->getPrimarySite()->id;

// Pro for the duration, in memory only.
$plugin->edition = Plugin::EDITION_PRO;

$settings = $plugin->getSettings();
$settings->notifyRequesterOnNew = false;
$settings->notifyAgentsOnNew = false;
$settings->notifyRequesterOnReply = false;
$settings->notifyAgentOnReply = false;
$settings->notifyOnSlaBreach = false;
$settings->queueNotifications = false;
$settings->rateLimit = 0;
$settings->minSubmitSeconds = 0;
$settings->captchaProvider = Settings::CAPTCHA_NONE;
$settings->trackSearches = true;
$settings->trackViews = true;
$settings->deflectionEnabled = true;
$settings->deflectionMinChars = 3;
$settings->slaEnabled = true;
$settings->pigeonBridge = false;
$settings->allowPrioritySelection = false;
$settings->satisfactionEnabled = true;
$settings->setSynonyms([['refund', 'money back', 'chargeback']]);
$settings->setBusinessHours([
    'monday' => ['open' => '09:00', 'close' => '17:00'],
    'tuesday' => ['open' => '09:00', 'close' => '17:00'],
    'wednesday' => ['open' => '09:00', 'close' => '17:00'],
    'thursday' => ['open' => '09:00', 'close' => '17:00'],
    'friday' => ['open' => '09:00', 'close' => '17:00'],
]);
$settings->setHolidays([]);
$plugin->setSettings($settings->toArray());
$settings = $plugin->getSettings();
$plugin->search->refresh();
$plugin->slas->refresh();

echo "CSR integration checks — edition {$plugin->edition}\n";

// Sweep anything a crashed earlier run left behind.
foreach (Article::find()->title($prefix . '*')->siteId('*')->unique()->status(null)->all() as $stale) {
    $elements->deleteElement($stale, true);
}

foreach (Ticket::find()->isSpam(null)->limit(null)->all() as $stale) {
    if (str_starts_with((string)$stale->title, $prefix)) {
        $elements->deleteElement($stale, true);
    }
}

foreach ($plugin->categories->getAllCategories() as $stale) {
    if (str_starts_with($stale->handle, $prefix)) {
        $plugin->categories->deleteCategoryById((int)$stale->id);
    }
}

Db::delete(Table::SEARCHES, ['like', 'normalizedQuery', $prefix . '%', false]);

// -----------------------------------------------------------------------------
section('Categories');

$billing = new Category([
    'name' => $prefix . ' Billing',
    'handle' => $prefix . 'Billing',
    'color' => 'green',
]);

check('a category saves', fn() => $plugin->categories->saveCategory($billing) ?: json_encode($billing->getErrors()));
check('it comes back by handle', fn() => $plugin->categories->getCategoryByHandle($prefix . 'Billing')?->id === $billing->id);

$refunds = new Category([
    'name' => $prefix . ' Refunds',
    'handle' => $prefix . 'Refunds',
    'parentId' => $billing->id,
]);

check('a child category saves', fn() => $plugin->categories->saveCategory($refunds) ?: json_encode($refunds->getErrors()));
check('the parent knows its children', function () use ($plugin, $billing, $refunds) {
    $ids = array_map(static fn(Category $c) => (int)$c->id, $plugin->categories->getChildren((int)$billing->id));

    return in_array((int)$refunds->id, $ids, true);
});

check('descendants come back with the parent', function () use ($plugin, $billing, $refunds) {
    $ids = $plugin->categories->getIdsWithDescendants((int)$billing->id);

    return in_array((int)$billing->id, $ids, true) && in_array((int)$refunds->id, $ids, true);
});

check('nesting stops at one level', function () use ($plugin, $prefix, $refunds) {
    $deep = new Category([
        'name' => $prefix . ' Too deep',
        'handle' => $prefix . 'TooDeep',
        'parentId' => $refunds->id,
    ]);

    return !$plugin->categories->saveCategory($deep) && $deep->hasErrors('parentId');
});

check('a category cannot be its own parent', function () use ($plugin, $billing) {
    $clone = clone $billing;
    $clone->parentId = $billing->id;

    return !$plugin->categories->saveCategory($clone) && $clone->hasErrors('parentId');
});

check('duplicate handles are refused', function () use ($plugin, $prefix) {
    $dupe = new Category(['name' => $prefix . ' Another', 'handle' => $prefix . 'Billing']);

    return !$plugin->categories->saveCategory($dupe) && $dupe->hasErrors('handle');
});

check('an emoji icon is escaped, not printed as markup', function () use ($prefix) {
    $category = new Category(['name' => 'x', 'handle' => 'x', 'icon' => '<b>hi</b> & bye']);

    return !str_contains($category->getIconHtml(), '<b>');
});

check('an SVG icon survives as markup', function () {
    $category = new Category(['name' => 'x', 'handle' => 'x', 'icon' => '<svg viewBox="0 0 10 10"><circle cx="5" cy="5" r="4"/></svg>']);

    return str_contains($category->getIconHtml(), '<svg');
});

check('a script inside an SVG icon does not', function () {
    $category = new Category(['name' => 'x', 'handle' => 'x', 'icon' => '<svg><script>alert(1)</script></svg>']);

    return !str_contains($category->getIconHtml(), '<script');
});

// -----------------------------------------------------------------------------
section('Articles');

$howToRefund = new Article([
    'siteId' => $siteId,
    'title' => $prefix . ' How do I get a refund?',
    'slug' => $prefix . '-refund',
    'categoryId' => $refunds->id,
    'excerpt' => 'Refunds are issued to the original payment method within five working days.',
    'keywords' => 'charged twice, money back, double billed',
    'enabled' => true,
]);

check('an article saves', fn() => $elements->saveElement($howToRefund) ?: json_encode($howToRefund->getErrors()));
check('it gets a URI from the site settings', fn() => $howToRefund->uri !== null ?: 'uri was null — site settings missing?');
check('it is found by its category', function () use ($siteId, $refunds, $howToRefund) {
    $ids = Article::find()->siteId($siteId)->categoryId($refunds->id)->ids();

    return in_array((int)$howToRefund->id, array_map('intval', $ids), true);
});

check('a parent category finds its children’s articles', function () use ($siteId, $plugin, $billing, $howToRefund) {
    $ids = Article::find()
        ->siteId($siteId)
        ->categoryId($plugin->categories->getIdsWithDescendants((int)$billing->id))
        ->ids();

    return in_array((int)$howToRefund->id, array_map('intval', $ids), true);
});

check('the category handle param finds it too', function () use ($siteId, $prefix, $howToRefund) {
    $ids = Article::find()->siteId($siteId)->category($prefix . 'Billing')->ids();

    return in_array((int)$howToRefund->id, array_map('intval', $ids), true);
});

check('an unknown category handle returns nothing, not everything', function () use ($siteId) {
    return Article::find()->siteId($siteId)->category('nope-not-a-real-handle')->count() === 0;
});

$unfiled = new Article([
    'siteId' => $siteId,
    'title' => $prefix . ' Unfiled article',
    'slug' => $prefix . '-unfiled',
    'enabled' => true,
]);
$elements->saveElement($unfiled);

check('categoryId false means "filed under nothing"', function () use ($siteId, $unfiled, $howToRefund) {
    $ids = array_map('intval', Article::find()->siteId($siteId)->categoryId(false)->ids());

    return in_array((int)$unfiled->id, $ids, true) && !in_array((int)$howToRefund->id, $ids, true);
});

check('categoryId null does not filter at all', function () use ($siteId, $unfiled, $howToRefund) {
    $ids = array_map('intval', Article::find()->siteId($siteId)->categoryId(null)->ids());

    return in_array((int)$unfiled->id, $ids, true) && in_array((int)$howToRefund->id, $ids, true);
});

check('deleting a category does not delete its articles', function () use ($plugin, $elements, $prefix, $siteId) {
    $doomed = new Category(['name' => $prefix . ' Doomed', 'handle' => $prefix . 'Doomed']);
    $plugin->categories->saveCategory($doomed);

    $article = new Article([
        'siteId' => $siteId,
        'title' => $prefix . ' Survivor',
        'slug' => $prefix . '-survivor',
        'categoryId' => $doomed->id,
        'enabled' => true,
    ]);
    $elements->saveElement($article);

    $plugin->categories->deleteCategoryById((int)$doomed->id);

    $reloaded = Article::find()->id($article->id)->siteId($siteId)->status(null)->one();
    $survived = $reloaded !== null && $reloaded->categoryId === null;

    $elements->deleteElement($reloaded ?? $article, true);

    return $survived ?: 'the article went with the category';
});

check('a child is promoted rather than deleted with its parent', function () use ($plugin, $prefix) {
    $parent = new Category(['name' => $prefix . ' Parent', 'handle' => $prefix . 'Parent']);
    $plugin->categories->saveCategory($parent);

    $child = new Category(['name' => $prefix . ' Child', 'handle' => $prefix . 'Child', 'parentId' => $parent->id]);
    $plugin->categories->saveCategory($child);

    $plugin->categories->deleteCategoryById((int)$parent->id);

    $reloaded = $plugin->categories->getCategoryById((int)$child->id);
    $promoted = $reloaded !== null && $reloaded->parentId === null;

    $plugin->categories->deleteCategoryById((int)$child->id);

    return $promoted ?: 'the child was deleted with the parent';
});

// -----------------------------------------------------------------------------
section('Who may read an article');

$group = Craft::$app->getUserGroups()->getAllGroups()[0] ?? null;

$restricted = new Article([
    'siteId' => $siteId,
    'title' => $prefix . ' Staff only',
    'slug' => $prefix . '-staff',
    'visibility' => Visibility::Users->value,
    'enabled' => true,
]);
$elements->saveElement($restricted);

check('a guest cannot see a users-only article', function () use ($siteId, $restricted) {
    $ids = array_map('intval', Article::find()->siteId($siteId)->forUser(false)->ids());

    return !in_array((int)$restricted->id, $ids, true);
});

check('a logged-in user can', function () use ($siteId, $restricted) {
    $user = User::find()->admin()->one();
    $ids = array_map('intval', Article::find()->siteId($siteId)->forUser($user)->ids());

    return in_array((int)$restricted->id, $ids, true);
});

check('the element agrees with the query for a guest', fn() => $restricted->isVisibleTo(null) === false);
check('the element agrees with the query for a user', function () use ($restricted) {
    return $restricted->isVisibleTo(User::find()->admin()->one()) === true;
});

check('"specific groups" with no groups chosen is refused', function () use ($siteId, $prefix) {
    $article = new Article([
        'siteId' => $siteId,
        'title' => $prefix . ' Nobody',
        'slug' => $prefix . '-nobody',
        'visibility' => Visibility::Groups->value,
    ]);

    return !$article->validate() && $article->hasErrors('visibility');
});

if ($group !== null) {
    $groupOnly = new Article([
        'siteId' => $siteId,
        'title' => $prefix . ' Group only',
        'slug' => $prefix . '-group',
        'visibility' => Visibility::Groups->value,
        'enabled' => true,
    ]);
    $groupOnly->setAllowedGroupIds([(int)$group->id]);
    $elements->saveElement($groupOnly);

    check('a group-restricted article is hidden from guests in SQL', function () use ($siteId, $groupOnly) {
        $ids = array_map('intval', Article::find()->siteId($siteId)->forUser(false)->ids());

        return !in_array((int)$groupOnly->id, $ids, true);
    });

    check('the group list survives a round trip through the column', function () use ($siteId, $groupOnly, $group) {
        // Re-read from the database rather than from Craft's identity map: a stored column with no
        // property or setter only throws when the element is genuinely loaded from a row.
        Craft::$app->getElements()->invalidateCachesForElement($groupOnly);
        $reloaded = Article::find()->id($groupOnly->id)->siteId($siteId)->status(null)->one();

        return $reloaded !== null && in_array((int)$group->id, $reloaded->getAllowedGroupIds(), true);
    });

    check('the element and the query agree about a group', function () use ($groupOnly, $group) {
        $member = User::find()->groupId($group->id)->one() ?? User::find()->admin()->one();

        return $groupOnly->isVisibleTo($member) === true;
    });
}

check('the visibility gate reaches search as well as listings', function () use ($plugin, $siteId, $restricted) {
    $results = $plugin->search->find('Staff only', ['siteId' => $siteId, 'log' => false]);
    $ids = array_map(static fn($r) => (int)$r->article->id, $results);

    // Nobody is logged in inside a console run, so this is the anonymous case.
    return !in_array((int)$restricted->id, $ids, true);
});

// -----------------------------------------------------------------------------
section('Search');

check('a query is normalised the way the reports group on it', function () use ($plugin) {
    return $plugin->search->normalize('  Refund?? ') === 'refund';
});

check('case and punctuation collapse to the same phrase', function () use ($plugin) {
    return $plugin->search->normalize('REFUND!') === $plugin->search->normalize('refund');
});

check('stop words are dropped', function () use ($plugin) {
    return !in_array('the', $plugin->search->tokenize('how do I get the refund'), true);
});

check('a query of nothing but stop words still tries', function () use ($plugin) {
    return $plugin->search->tokenize('how do I') !== [];
});

check('synonyms expand', function () use ($plugin) {
    $expanded = $plugin->search->expand(['refund']);

    return in_array('chargeback', $expanded, true) && in_array('refund', $expanded, true);
});

check('an article is found by its title', function () use ($plugin, $siteId, $howToRefund) {
    $results = $plugin->search->find('refund', ['siteId' => $siteId, 'log' => false]);

    return in_array((int)$howToRefund->id, array_map(static fn($r) => (int)$r->article->id, $results), true);
});

check('and by a phrase that only appears in its keywords', function () use ($plugin, $siteId, $howToRefund) {
    // "charged twice" is nowhere in the title or the body. This is the whole point of the column.
    $results = $plugin->search->find('charged twice', ['siteId' => $siteId, 'log' => false]);

    return in_array((int)$howToRefund->id, array_map(static fn($r) => (int)$r->article->id, $results), true);
});

check('and by a synonym the site configured', function () use ($plugin, $siteId, $howToRefund) {
    $results = $plugin->search->find('chargeback', ['siteId' => $siteId, 'log' => false]);

    return in_array((int)$howToRefund->id, array_map(static fn($r) => (int)$r->article->id, $results), true);
});

$strongId = (int)$howToRefund->id;
check('a title match outranks a body-only match', function () use ($plugin, $siteId, $elements, $prefix, $strongId) {
    $mentions = new Article([
        'siteId' => $siteId,
        'title' => $prefix . ' Something else entirely',
        'slug' => $prefix . '-else',
        'excerpt' => 'This page mentions a refund in passing and is not about refunds.',
        'enabled' => true,
    ]);
    $elements->saveElement($mentions);

    $results = $plugin->search->find('refund', ['siteId' => $siteId, 'log' => false]);
    $first = $results[0]->article->id ?? null;

    $elements->deleteElement($mentions, true);

    return (int)$first === (int)$strongId ?: 'the weaker match came first';
});

check('nonsense returns nothing', function () use ($plugin, $siteId) {
    return $plugin->search->find('zzzqqxplat', ['siteId' => $siteId, 'log' => false]) === [];
});

check('a search is written down', function () use ($plugin, $siteId, $prefix) {
    $plugin->search->log($prefix . ' widget', 0, $siteId);

    return (new craft\db\Query())
        ->from([Table::SEARCHES])
        ->where(['normalizedQuery' => $plugin->search->normalize($prefix . ' widget')])
        ->exists();
});

check('a failed search is distinguishable from a successful one', function () use ($plugin, $siteId, $prefix) {
    $plugin->search->log($prefix . ' nothingatall', 0, $siteId);
    $plugin->search->log($prefix . ' somethingfound', 3, $siteId);

    $failed = $plugin->analytics->getFailedSearches(30, 50, $siteId);
    $queries = array_column($failed, 'query');

    return in_array($plugin->search->normalize($prefix . ' nothingatall'), $queries, true)
        && !in_array($plugin->search->normalize($prefix . ' somethingfound'), $queries, true);
});

check('a click is attached to the search that produced it', function () use ($plugin, $siteId, $prefix, $howToRefund) {
    $phrase = $prefix . ' clickable';
    $plugin->search->log($phrase, 1, $siteId);

    $recorded = $plugin->search->recordClick($phrase, (int)$howToRefund->id, $siteId);

    $row = (new craft\db\Query())
        ->from([Table::SEARCHES])
        ->where(['normalizedQuery' => $plugin->search->normalize($phrase)])
        ->one();

    return $recorded && (int)$row['clickedArticleId'] === (int)$howToRefund->id;
});

check('searches nobody clicked are reported separately', function () use ($plugin, $siteId, $prefix) {
    $phrase = $prefix . ' ignoredresults';

    for ($i = 0; $i < 3; $i++) {
        $plugin->search->log($phrase, 5, $siteId);
    }

    $rows = $plugin->analytics->getUnclickedSearches(30, 50, $siteId);

    return in_array($plugin->search->normalize($phrase), array_column($rows, 'query'), true);
});

check('search logging can be switched off', function () use ($plugin, $siteId, $prefix, $settings) {
    $settings->trackSearches = false;
    $result = $plugin->search->log($prefix . ' shouldnotexist', 0, $siteId);
    $settings->trackSearches = true;

    return $result === null;
});

// -----------------------------------------------------------------------------
section('Was this helpful?');

check('a vote is recorded and counted', function () use ($plugin, $howToRefund) {
    $before = $howToRefund->helpfulCount;
    $plugin->ratings->rate($howToRefund, true);

    return $howToRefund->helpfulCount === $before + 1;
});

check('the same visitor voting again does not count twice', function () use ($plugin, $howToRefund) {
    $before = $howToRefund->helpfulCount;
    $plugin->ratings->rate($howToRefund, true);

    return $howToRefund->helpfulCount === $before;
});

check('changing your mind moves the vote rather than adding one', function () use ($plugin, $howToRefund) {
    $helpful = $howToRefund->helpfulCount;
    $unhelpful = $howToRefund->unhelpfulCount;

    $plugin->ratings->rate($howToRefund, false);

    return $howToRefund->helpfulCount === $helpful - 1 && $howToRefund->unhelpfulCount === $unhelpful + 1;
});

check('the counters match the rows', function () use ($plugin, $howToRefund, $siteId) {
    $plugin->articles->recountRatings((int)$howToRefund->id);

    $row = (new craft\db\Query())
        ->select(['helpfulCount', 'unhelpfulCount'])
        ->from([Table::ARTICLES])
        ->where(['id' => $howToRefund->id])
        ->one();

    $helpful = (int)(new craft\db\Query())->from([Table::RATINGS])->where(['articleId' => $howToRefund->id, 'helpful' => true])->count();
    $unhelpful = (int)(new craft\db\Query())->from([Table::RATINGS])->where(['articleId' => $howToRefund->id, 'helpful' => false])->count();

    return (int)$row['helpfulCount'] === $helpful && (int)$row['unhelpfulCount'] === $unhelpful;
});

check('a comment is kept when a later vote arrives without one', function () use ($plugin, $howToRefund) {
    $plugin->ratings->rate($howToRefund, false, 'It never says how long it takes.');
    $plugin->ratings->rate($howToRefund, false);

    $feedback = $plugin->ratings->getFeedback((int)$howToRefund->id, 10);

    return $feedback !== [] && str_contains((string)$feedback[0]['comment'], 'how long');
});

check('a decrement cannot underflow the unsigned column', function () use ($plugin, $elements, $siteId, $prefix) {
    // The counters start at zero, and the first vote here is a "no" changed to a "yes" — which
    // decrements a column that is already zero unless the CASE guard is doing its job. On MySQL an
    // unguarded GREATEST(0, col - 1) raises "BIGINT UNSIGNED value is out of range" and fails the
    // whole request.
    $article = new Article([
        'siteId' => $siteId,
        'title' => $prefix . ' Underflow',
        'slug' => $prefix . '-underflow',
        'enabled' => true,
    ]);
    $elements->saveElement($article);

    Db::update(Table::ARTICLES, ['helpfulCount' => 0, 'unhelpfulCount' => 0], ['id' => $article->id]);

    $plugin->ratings->rate($article, false);
    $plugin->ratings->rate($article, true);

    $row = (new craft\db\Query())->select(['unhelpfulCount'])->from([Table::ARTICLES])->where(['id' => $article->id])->one();
    $ok = (int)$row['unhelpfulCount'] === 0;

    $elements->deleteElement($article, true);

    return $ok;
});

check('an article that does not take ratings refuses them', function () use ($plugin, $elements, $siteId, $prefix) {
    $article = new Article([
        'siteId' => $siteId,
        'title' => $prefix . ' No ratings',
        'slug' => $prefix . '-noratings',
        'allowRatings' => false,
        'enabled' => true,
    ]);
    $elements->saveElement($article);

    $result = $plugin->ratings->rate($article, true);
    $elements->deleteElement($article, true);

    return $result === null;
});

check('a score is not called meaningful until enough people have voted', function () use ($howToRefund) {
    return $howToRefund->getRatingCount() < 5
        ? $howToRefund->getHasMeaningfulRating() === false
        : $howToRefund->getHasMeaningfulRating() === true;
});

// -----------------------------------------------------------------------------
section('Views');

check('a view is counted', function () use ($plugin, $howToRefund, $siteId) {
    $before = $howToRefund->viewCount;
    $plugin->articles->recordView($howToRefund, $siteId);

    return $howToRefund->viewCount === $before + 1;
});

check('the daily rollup accumulates rather than overwriting', function () use ($plugin, $howToRefund, $siteId) {
    $plugin->articles->recordView($howToRefund, $siteId);
    $plugin->articles->recordView($howToRefund, $siteId);

    $views = (new craft\db\Query())
        ->select(['views'])
        ->from([Table::VIEWS])
        ->where(['articleId' => $howToRefund->id, 'siteId' => $siteId, 'date' => (new DateTime())->format('Y-m-d')])
        ->scalar();

    return (int)$views >= 3;
});

check('view tracking can be switched off', function () use ($plugin, $howToRefund, $siteId, $settings) {
    $settings->trackViews = false;
    $before = $howToRefund->viewCount;
    $plugin->articles->recordView($howToRefund, $siteId);
    $settings->trackViews = true;

    return $howToRefund->viewCount === $before;
});

check('popular articles come back', function () use ($plugin, $siteId, $howToRefund) {
    $ids = array_map(static fn(Article $a) => (int)$a->id, $plugin->articles->getPopular($siteId, 10));

    return in_array((int)$howToRefund->id, $ids, true);
});

// -----------------------------------------------------------------------------
section('Related articles');

check('hand-picked relations are stored and read back', function () use ($plugin, $elements, $siteId, $howToRefund, $unfiled) {
    $howToRefund->setRelatedIds([(int)$unfiled->id]);
    $elements->saveElement($howToRefund);

    $ids = array_map(static fn(Article $a) => (int)$a->id, $plugin->articles->getExplicitRelated($howToRefund));

    return $ids === [(int)$unfiled->id];
});

check('an article cannot be related to itself', function () use ($plugin, $elements, $howToRefund, $unfiled) {
    $howToRefund->setRelatedIds([(int)$howToRefund->id, (int)$unfiled->id]);
    $elements->saveElement($howToRefund);

    $ids = array_map(static fn(Article $a) => (int)$a->id, $plugin->articles->getExplicitRelated($howToRefund));

    return !in_array((int)$howToRefund->id, $ids, true);
});

check('a save that says nothing about relations leaves them alone', function () use ($plugin, $elements, $howToRefund) {
    $before = count($plugin->articles->getExplicitRelated($howToRefund));

    // Null means "the caller had no opinion" — the shape a partial save produces.
    $howToRefund->setRelatedIds(null);
    $elements->saveElement($howToRefund);

    return count($plugin->articles->getExplicitRelated($howToRefund)) === $before;
});

check('the fallback fills an empty "see also" from the same category', function () use ($plugin, $elements, $siteId, $prefix, $refunds) {
    $a = new Article(['siteId' => $siteId, 'title' => $prefix . ' Rel A', 'slug' => $prefix . '-rel-a', 'categoryId' => $refunds->id, 'enabled' => true]);
    $b = new Article(['siteId' => $siteId, 'title' => $prefix . ' Rel B', 'slug' => $prefix . '-rel-b', 'categoryId' => $refunds->id, 'enabled' => true]);
    $elements->saveElement($a);
    $elements->saveElement($b);

    $related = $plugin->articles->getRelated($a, 4);
    $ok = $related !== [] && !in_array((int)$a->id, array_map(static fn(Article $x) => (int)$x->id, $related), true);

    $elements->deleteElement($a, true);
    $elements->deleteElement($b, true);

    return $ok ?: 'the fallback returned nothing';
});

// -----------------------------------------------------------------------------
section('Statuses');

check('the installer left one status of each kind', function () use ($plugin) {
    foreach (StatusKind::cases() as $kind) {
        if ($plugin->statuses->getFirstOfKind($kind) === null) {
            return "no status of kind {$kind->value}";
        }
    }

    return true;
});

check('there is exactly one default', function () use ($plugin) {
    $defaults = array_filter($plugin->statuses->getAllStatuses(), static fn(Status $s) => $s->isDefault);

    return count($defaults) === 1;
});

check('open and finished are distinguishable', function () use ($plugin) {
    return $plugin->statuses->getFirstOfKind(StatusKind::Open)->isOpen() === true
        && $plugin->statuses->getFirstOfKind(StatusKind::Closed)->isOpen() === false;
});

check('pending pauses the clock and nothing else does', function () {
    return StatusKind::Pending->pausesClock() === true
        && StatusKind::Open->pausesClock() === false
        && StatusKind::Resolved->pausesClock() === false;
});

check('Lite caps statuses at five', function () use ($plugin) {
    return Edition::statusLimitReached(5, false) === true
        && Edition::statusLimitReached(4, false) === false
        && Edition::statusLimitReached(500, true) === false;
});

// -----------------------------------------------------------------------------
section('Raising a ticket');

$ticket = $plugin->tickets->submit([
    'name' => 'Ada Lovelace',
    'email' => 'ada@example.com',
    'subject' => $prefix . ' I was charged twice',
    'message' => 'My card shows two identical charges from you this morning.',
    'categoryId' => $refunds->id,
    'source' => TicketSource::Web,
    'siteId' => $siteId,
    'requesterId' => null,
]);

check('a ticket is raised', fn() => $ticket->id !== null ?: json_encode($ticket->getErrors()));
check('it gets a sequential number, not an element ID', fn() => $ticket->number >= $settings->ticketNumberStart);
check('it gets a reference of exactly 32 characters', fn() => strlen((string)$ticket->reference) === 32);
check('it lands on the default status', fn() => $ticket->statusId === $plugin->statuses->getDefaultStatus()?->id);
check('the requester’s name and address are kept as columns', function () use ($ticket) {
    return $ticket->requesterEmail === 'ada@example.com' && $ticket->requesterName === 'Ada Lovelace';
});
check('a guest ticket is not attributed to whoever is signed in', fn() => $ticket->requesterId === null);
check('the site it came from is recorded', fn() => $ticket->submittedSiteId === $siteId);
check('a "created" event was written', function () use ($plugin, $ticket) {
    $types = array_map(static fn($e) => $e->type, $plugin->tickets->getEvents((int)$ticket->id));

    return in_array('created', $types, true);
});

check('the next ticket gets the next number', function () use ($plugin, $ticket, $siteId, $prefix, $elements) {
    $second = $plugin->tickets->submit([
        'email' => 'grace@example.com',
        'subject' => $prefix . ' Second ticket',
        'message' => 'Another question.',
        'siteId' => $siteId,
        'requesterId' => null,
    ]);

    $ok = $second->number === $ticket->number + 1;
    $elements->deleteElement($second, true);

    return $ok ?: "got {$second->number}, expected " . ($ticket->number + 1);
});

check('a reference finds the ticket', function () use ($plugin, $ticket) {
    return $plugin->tickets->getTicketByReference((string)$ticket->reference)?->id === $ticket->id;
});

check('a wrong reference finds nothing', function () use ($plugin) {
    return $plugin->tickets->getTicketByReference(str_repeat('a', 32)) === null;
});

check('a reference of the wrong length is refused without a query', function () use ($plugin) {
    return $plugin->tickets->getTicketByReference('short') === null;
});

check('a number finds the ticket', function () use ($plugin, $ticket) {
    return $plugin->tickets->getTicketByNumber($ticket->number)?->id === $ticket->id;
});

// The trust boundary.
section('What the support form refuses to believe');

check('a category that does not take tickets is ignored', function () use ($plugin, $siteId, $prefix, $elements) {
    $docsOnly = new Category([
        'name' => $prefix . ' Docs only',
        'handle' => $prefix . 'DocsOnly',
        'acceptsTickets' => false,
    ]);
    $plugin->categories->saveCategory($docsOnly);

    $attempt = $plugin->tickets->submit([
        'email' => 'x@example.com',
        'subject' => $prefix . ' Trying a documentation-only category',
        'message' => 'x',
        'categoryId' => $docsOnly->id,
        'siteId' => $siteId,
        'requesterId' => null,
    ]);

    $ok = $attempt->categoryId === null;
    $elements->deleteElement($attempt, true);
    $plugin->categories->deleteCategoryById((int)$docsOnly->id);

    return $ok ?: 'the category was accepted anyway';
});

check('a category that does not exist is ignored', function () use ($plugin, $siteId, $prefix, $elements) {
    $attempt = $plugin->tickets->submit([
        'email' => 'x@example.com',
        'subject' => $prefix . ' Bogus category',
        'message' => 'x',
        'categoryId' => 99999999,
        'siteId' => $siteId,
        'requesterId' => null,
    ]);

    $ok = $attempt->categoryId === null;
    $elements->deleteElement($attempt, true);

    return $ok;
});

check('a chosen priority is ignored unless the site allows it', function () use ($plugin, $siteId, $prefix, $elements, $settings) {
    $settings->allowPrioritySelection = false;

    $attempt = $plugin->tickets->submit([
        'email' => 'x@example.com',
        'subject' => $prefix . ' Very urgent indeed',
        'message' => 'x',
        'priority' => Priority::Urgent->value,
        'siteId' => $siteId,
        'requesterId' => null,
    ]);

    $ok = $attempt->priority === $settings->getDefaultPriority()->value;
    $elements->deleteElement($attempt, true);

    return $ok ?: 'the visitor set their own priority';
});

check('and honoured when it does', function () use ($plugin, $siteId, $prefix, $elements, $settings) {
    $settings->allowPrioritySelection = true;

    $attempt = $plugin->tickets->submit([
        'email' => 'x@example.com',
        'subject' => $prefix . ' Genuinely urgent',
        'message' => 'x',
        'priority' => Priority::Urgent->value,
        'siteId' => $siteId,
        'requesterId' => null,
    ]);

    $ok = $attempt->priority === Priority::Urgent->value;
    $settings->allowPrioritySelection = false;
    $elements->deleteElement($attempt, true);

    return $ok;
});

check('an invented priority falls back to the default', function () use ($plugin, $siteId, $prefix, $elements, $settings) {
    $settings->allowPrioritySelection = true;

    $attempt = $plugin->tickets->submit([
        'email' => 'x@example.com',
        'subject' => $prefix . ' Catastrophic',
        'message' => 'x',
        'priority' => 'apocalyptic',
        'siteId' => $siteId,
        'requesterId' => null,
    ]);

    $ok = $attempt->priority === $settings->getDefaultPriority()->value;
    $settings->allowPrioritySelection = false;
    $elements->deleteElement($attempt, true);

    return $ok;
});

check('a ticket with no subject borrows the start of the message', function () use ($plugin, $siteId, $elements) {
    $attempt = $plugin->tickets->submit([
        'email' => 'x@example.com',
        'subject' => '',
        'message' => 'The checkout page returns a five hundred error when I click pay.',
        'siteId' => $siteId,
        'requesterId' => null,
    ]);

    $ok = str_starts_with((string)$attempt->title, 'The checkout page');
    $elements->deleteElement($attempt, true);

    return $ok;
});

check('a ticket without an email address is refused', function () use ($plugin, $siteId, $prefix) {
    $attempt = $plugin->tickets->submit([
        'email' => '',
        'subject' => $prefix . ' No address',
        'message' => 'x',
        'siteId' => $siteId,
        'requesterId' => null,
    ]);

    return $attempt->id === null && $attempt->hasErrors('requesterEmail');
});

// -----------------------------------------------------------------------------
section('Custom questions on the form');

$orderNumber = new FormField([
    'name' => $prefix . ' Order number',
    'handle' => $prefix . 'OrderNumber',
    'type' => FieldType::Text->value,
    'required' => false,
]);

check('a question saves', fn() => $plugin->forms->saveField($orderNumber) ?: json_encode($orderNumber->getErrors()));

check('the form’s own field names are refused as handles', function () use ($prefix) {
    $clash = new FormField(['name' => 'x', 'handle' => 'email', 'type' => FieldType::Text->value]);

    return !$clash->validate() && $clash->hasErrors('handle');
});

check('a dropdown with no options is refused', function () {
    $empty = new FormField(['name' => 'x', 'handle' => 'xyz', 'type' => FieldType::Select->value]);

    return !$empty->validate() && $empty->hasErrors('options');
});

check('answers are read from the form, not from the request', function () use ($plugin, $orderNumber) {
    $answers = $plugin->forms->collect([
        $orderNumber->handle => 'ORD-123',
        'somethingNobodyDefined' => 'should be ignored',
    ]);

    return isset($answers[$orderNumber->handle])
        && !array_key_exists('somethingNobodyDefined', $answers);
});

check('a dropdown only accepts values it offers', function () use ($plugin, $prefix) {
    $plan = new FormField([
        'name' => $prefix . ' Plan',
        'handle' => $prefix . 'Plan',
        'type' => FieldType::Select->value,
    ]);
    $plan->setOptions([['label' => 'Free', 'value' => 'free'], ['label' => 'Paid', 'value' => 'paid']]);
    $plugin->forms->saveField($plan);

    $good = $plugin->forms->collect([$plan->handle => 'paid']);
    $bad = $plugin->forms->collect([$plan->handle => 'enterprise-tier-i-invented']);

    $ok = ($good[$plan->handle] ?? null) === 'paid' && !array_key_exists($plan->handle, $bad);
    $plugin->forms->deleteFieldById((int)$plan->id);

    return $ok;
});

check('an array posted to a single-value question is discarded', function () use ($plugin, $orderNumber) {
    $answers = $plugin->forms->collect([$orderNumber->handle => ['a', 'b']]);

    return !array_key_exists($orderNumber->handle, $answers);
});

check('a text answer is length-capped', function () use ($plugin, $orderNumber) {
    $answers = $plugin->forms->collect([$orderNumber->handle => str_repeat('x', 5000)]);

    return mb_strlen((string)$answers[$orderNumber->handle]) <= 500;
});

check('a required question that was not answered is caught', function () use ($plugin, $prefix) {
    $required = new FormField([
        'name' => $prefix . ' Required thing',
        'handle' => $prefix . 'RequiredThing',
        'type' => FieldType::Text->value,
        'required' => true,
    ]);
    $plugin->forms->saveField($required);

    $errors = $plugin->forms->validateAnswers([]);
    $ok = isset($errors[$required->handle]);

    $plugin->forms->deleteFieldById((int)$required->id);

    return $ok;
});

check('a question only shown for some categories is not asked elsewhere', function () use ($plugin, $prefix, $billing, $refunds) {
    $scoped = new FormField([
        'name' => $prefix . ' Billing only',
        'handle' => $prefix . 'BillingOnly',
        'type' => FieldType::Text->value,
    ]);
    $scoped->setCategoryUids([(string)$billing->uid]);
    $plugin->forms->saveField($scoped);

    $inBilling = $scoped->appliesTo($billing);
    $inRefunds = $scoped->appliesTo($refunds);
    $nowhere = $scoped->appliesTo(null);

    $plugin->forms->deleteFieldById((int)$scoped->id);

    return $inBilling === true && $inRefunds === false && $nowhere === false;
});

check('answers survive the question being deleted', function () use ($plugin, $siteId, $prefix, $elements, $orderNumber) {
    $withAnswer = $plugin->tickets->submit([
        'email' => 'x@example.com',
        'subject' => $prefix . ' Has an answer',
        'message' => 'x',
        'answers' => [$orderNumber->handle => 'ORD-999'],
        'siteId' => $siteId,
        'requesterId' => null,
    ]);

    $handle = $orderNumber->handle;
    $plugin->forms->deleteFieldById((int)$orderNumber->id);

    $reloaded = Ticket::find()->id($withAnswer->id)->isSpam(null)->one();
    $answers = $reloaded?->getAnswers() ?? [];

    // Still there, and still listed — labelled by handle rather than thrown away.
    $listed = array_column($reloaded?->getAnsweredQuestions() ?? [], 'label');
    $ok = ($answers[$handle] ?? null) === 'ORD-999' && in_array($handle, $listed, true);

    $elements->deleteElement($withAnswer, true);

    return $ok;
});

check('custom questions are a Pro feature', function () use ($plugin, $prefix) {
    $plugin->edition = Plugin::EDITION_LITE;
    $plugin->forms->refresh();

    $blocked = new FormField(['name' => $prefix . ' Lite', 'handle' => $prefix . 'Lite', 'type' => FieldType::Text->value]);
    $refused = !$plugin->forms->saveField($blocked);
    $empty = $plugin->forms->getFieldsFor(null) === [];

    $plugin->edition = Plugin::EDITION_PRO;
    $plugin->forms->refresh();

    return $refused && $empty;
});

// -----------------------------------------------------------------------------
section('Replying');

check('an agent reply stamps the first response', function () use ($plugin, $ticket) {
    $plugin->replies->post($ticket, [
        'body' => 'We can see both charges and have refunded the second.',
        'byAgent' => true,
        'notify' => false,
    ]);

    return $ticket->firstRespondedAt !== null;
});

check('and moves a new ticket to open', function () use ($plugin, $ticket) {
    return $ticket->getTicketStatus()?->getKind() === StatusKind::Open;
});

check('a second agent reply does not move the first-response stamp', function () use ($plugin, $ticket) {
    $first = $ticket->firstRespondedAt?->getTimestamp();

    $plugin->replies->post($ticket, ['body' => 'Anything else?', 'byAgent' => true, 'notify' => false]);

    return $ticket->firstRespondedAt?->getTimestamp() === $first;
});

check('an internal note does not stamp a first response', function () use ($plugin, $siteId, $prefix, $elements) {
    $fresh = $plugin->tickets->submit([
        'email' => 'note@example.com',
        'subject' => $prefix . ' Note only',
        'message' => 'x',
        'siteId' => $siteId,
        'requesterId' => null,
    ]);

    $plugin->replies->post($fresh, ['body' => 'Looks like the payment gateway again.', 'isNote' => true, 'byAgent' => true, 'notify' => false]);

    $ok = $fresh->firstRespondedAt === null;
    $elements->deleteElement($fresh, true);

    return $ok ?: 'writing a note to yourself counted as answering the customer';
});

check('a note never reaches the customer’s own view', function () use ($plugin, $ticket) {
    $plugin->replies->post($ticket, ['body' => 'Internal: check the gateway logs.', 'isNote' => true, 'byAgent' => true, 'notify' => false]);

    $public = array_map(static fn($r) => $r->body, $ticket->getPublicReplies());

    foreach ($public as $body) {
        if (str_contains($body, 'gateway logs')) {
            return 'the note appeared in the public replies';
        }
    }

    return true;
});

check('the timeline still contains it for the desk', function () use ($ticket) {
    foreach ($ticket->getReplies() as $reply) {
        if ($reply->isNote && str_contains($reply->body, 'gateway logs')) {
            return true;
        }
    }

    return 'the note is not in the full timeline either';
});

check('a customer reply says the ball is with us', function () use ($plugin, $ticket) {
    $plugin->replies->post($ticket, [
        'body' => 'Thank you, I can see the refund.',
        'byAgent' => false,
        'notify' => false,
    ]);

    return $ticket->lastReplyBy === 'requester' && $ticket->getIsAwaitingUs() === true;
});

check('reply bodies are purified', function () use ($plugin, $ticket) {
    $reply = $plugin->replies->post($ticket, [
        'body' => 'Hello <script>alert(1)</script><strong>there</strong><iframe src="x"></iframe>',
        'byAgent' => false,
        'notify' => false,
    ]);

    return $reply !== null
        && !str_contains($reply->body, '<script')
        && !str_contains($reply->body, '<iframe')
        && str_contains($reply->body, '<strong>');
});

check('an empty reply is refused', function () use ($plugin, $ticket) {
    return $plugin->replies->post($ticket, ['body' => '   ', 'notify' => false]) === null;
});

check('the reply counters agree with the rows', function () use ($plugin, $ticket) {
    $plugin->tickets->recountReplies((int)$ticket->id);

    $reloaded = Ticket::find()->id($ticket->id)->isSpam(null)->one();
    $rows = (int)(new craft\db\Query())->from([Table::REPLIES])->where(['ticketId' => $ticket->id, 'isNote' => false])->count();

    return (int)$reloaded->replyCount === $rows;
});

// -----------------------------------------------------------------------------
section('Statuses moving');

check('resolving stamps a resolution date', function () use ($plugin, $ticket) {
    $resolved = $plugin->statuses->getFirstOfKind(StatusKind::Resolved);
    $plugin->tickets->setStatus($ticket, (int)$resolved->id, true, false);

    return $ticket->resolvedAt !== null;
});

check('reopening clears it', function () use ($plugin, $ticket) {
    $open = $plugin->statuses->getFirstOfKind(StatusKind::Open);
    $plugin->tickets->setStatus($ticket, (int)$open->id, true, false);

    return $ticket->resolvedAt === null && $ticket->closedAt === null;
});

check('a reopening is recorded', function () use ($plugin, $ticket) {
    $types = array_map(static fn($e) => $e->type, $plugin->tickets->getEvents((int)$ticket->id));

    return in_array('reopened', $types, true);
});

check('closing stamps both dates', function () use ($plugin, $ticket) {
    $closed = $plugin->statuses->getFirstOfKind(StatusKind::Closed);
    $plugin->tickets->setStatus($ticket, (int)$closed->id, true, false);

    return $ticket->closedAt !== null && $ticket->resolvedAt !== null;
});

check('a reply on a closed ticket reopens it', function () use ($plugin, $ticket) {
    $plugin->replies->post($ticket, ['body' => 'Actually, it happened again.', 'byAgent' => false, 'notify' => false]);

    return $ticket->getTicketStatus()?->isOpen() === true;
});

check('a status change is written to the history with both names', function () use ($plugin, $ticket) {
    foreach ($plugin->tickets->getEvents((int)$ticket->id) as $event) {
        if ($event->type === 'status' && ($event->getData()['from'] ?? null) && ($event->getData()['to'] ?? null)) {
            return true;
        }
    }

    return 'no status event carried both names';
});

check('the "open only" query uses kinds, not names', function () use ($plugin, $ticket) {
    $ids = array_map('intval', Ticket::find()->openOnly()->limit(null)->ids());

    return in_array((int)$ticket->id, $ids, true);
});

check('a desk with no matching status returns nothing rather than everything', function () {
    // Nothing is of kind "new" *and* assigned to user zero, so this must be empty rather than the
    // whole table — the failure mode of an unmatched IN () that silently drops its condition.
    return (int)Ticket::find()->statusKind(StatusKind::New)->agentId(0)->count() === 0;
});

// -----------------------------------------------------------------------------
section('Assignment');

$admin = User::find()->admin()->one();

check('a ticket can be assigned', function () use ($plugin, $ticket, $admin) {
    $plugin->tickets->assign($ticket, (int)$admin->id);

    return $ticket->agentId === (int)$admin->id;
});

check('assigning is recorded with the agent’s name', function () use ($plugin, $ticket) {
    foreach ($plugin->tickets->getEvents((int)$ticket->id) as $event) {
        if ($event->type === 'assigned' && ($event->getData()['agent'] ?? '') !== '') {
            return true;
        }
    }

    return 'no assignment event carried a name';
});

check('unassigned is false, not null', function () use ($plugin, $ticket, $admin) {
    $plugin->tickets->assign($ticket, null);

    $unassigned = array_map('intval', Ticket::find()->agentId(false)->limit(null)->ids());
    $ok = in_array((int)$ticket->id, $unassigned, true);

    $plugin->tickets->assign($ticket, (int)$admin->id);

    return $ok;
});

check('agentId null does not filter at all', function () use ($ticket) {
    $all = array_map('intval', Ticket::find()->agentId(null)->isSpam(null)->limit(null)->ids());

    return in_array((int)$ticket->id, $all, true);
});

// -----------------------------------------------------------------------------
section('Service levels');

$policy = new SlaPolicy([
    'name' => $prefix . ' Standard',
    'handle' => $prefix . 'Standard',
    'firstResponseMinutes' => 240,
    'resolutionMinutes' => 2880,
    'businessHoursOnly' => true,
    'enabled' => true,
]);

check('a policy saves', fn() => $plugin->slas->savePolicy($policy) ?: json_encode($policy->getErrors()));

check('a policy that promises nothing is refused', function () use ($prefix) {
    $empty = new SlaPolicy(['name' => 'x', 'handle' => 'xslapolicy']);

    return !$empty->validate() && $empty->hasErrors('firstResponseMinutes');
});

check('resolution before first response is refused', function () {
    $backwards = new SlaPolicy([
        'name' => 'x', 'handle' => 'xbackwards',
        'firstResponseMinutes' => 600, 'resolutionMinutes' => 60,
    ]);

    return !$backwards->validate() && $backwards->hasErrors('resolutionMinutes');
});

check('an empty condition means "do not care", not "match nothing"', function () use ($ticket, $policy) {
    return $policy->matches($ticket) === true;
});

check('a stated priority condition is honoured', function () use ($ticket, $policy) {
    $urgentOnly = clone $policy;
    $urgentOnly->setConditions(['priorities' => [Priority::Urgent->value]]);

    return $urgentOnly->matches($ticket) === false;
});

check('a stated category condition is honoured', function () use ($ticket, $policy, $refunds, $billing) {
    $refundsOnly = clone $policy;
    $refundsOnly->setConditions(['categoryUids' => [(string)$refunds->uid]]);

    $billingOnly = clone $policy;
    $billingOnly->setConditions(['categoryUids' => [(string)$billing->uid]]);

    return $refundsOnly->matches($ticket) === true && $billingOnly->matches($ticket) === false;
});

check('a disabled policy claims nothing', function () use ($ticket, $policy) {
    $off = clone $policy;
    $off->enabled = false;

    return $off->matches($ticket) === false;
});

check('the first matching policy wins', function () use ($plugin, $ticket, $prefix) {
    $specific = new SlaPolicy([
        'name' => $prefix . ' Urgent only',
        'handle' => $prefix . 'UrgentOnly',
        'firstResponseMinutes' => 30,
        'sortOrder' => 0,
    ]);
    $specific->setConditions(['priorities' => [Priority::Urgent->value]]);
    $plugin->slas->savePolicy($specific);

    // This ticket is `normal`, so the specific policy must not claim it.
    $matched = $plugin->slas->match($ticket);
    $ok = $matched !== null && $matched->handle !== $specific->handle;

    $plugin->slas->deletePolicyById((int)$specific->id);

    return $ok;
});

// The arithmetic. Fixed points, so the result does not depend on when the suite runs.
section('The SLA clock');

check('four hours from Monday 10am is Monday 2pm', function () use ($plugin) {
    $due = $plugin->slas->due(new DateTime('2026-03-02 10:00:00'), 240, true);

    return $due->format('Y-m-d H:i') === '2026-03-02 14:00';
});

check('four hours from Friday 4pm lands on Monday, not Saturday', function () use ($plugin) {
    // The whole reason opening hours exist: a four-hour promise made at four on a Friday is
    // otherwise a breach scheduled for Saturday morning.
    $due = $plugin->slas->due(new DateTime('2026-03-06 16:00:00'), 240, true);

    // One hour on Friday afternoon, then three from Monday's opening: 12:00, not 11:00.
    return $due->format('Y-m-d H:i') === '2026-03-09 12:00'
        ?: 'got ' . $due->format('Y-m-d H:i');
});

check('and lands on Friday evening when hours are switched off', function () use ($plugin) {
    $due = $plugin->slas->due(new DateTime('2026-03-06 16:00:00'), 240, false);

    return $due->format('Y-m-d H:i') === '2026-03-06 20:00';
});

check('a target starting before opening waits for the doors', function () use ($plugin) {
    $due = $plugin->slas->due(new DateTime('2026-03-02 06:00:00'), 60, true);

    return $due->format('Y-m-d H:i') === '2026-03-02 10:00' ?: 'got ' . $due->format('Y-m-d H:i');
});

check('a holiday is skipped', function () use ($plugin, $settings) {
    $settings->setHolidays(['2026-03-03']);
    $plugin->slas->refresh();

    $due = $plugin->slas->due(new DateTime('2026-03-02 16:00:00'), 120, true);
    $ok = $due->format('Y-m-d') === '2026-03-04';

    $settings->setHolidays([]);
    $plugin->slas->refresh();

    return $ok ?: 'got ' . $due->format('Y-m-d H:i');
});

check('business minutes between two moments skips the weekend', function () use ($plugin) {
    // Friday 4pm to Monday 10am is one working hour on Friday plus one on Monday.
    $minutes = $plugin->slas->minutesBetween(
        new DateTime('2026-03-06 16:00:00'),
        new DateTime('2026-03-09 10:00:00'),
        true,
    );

    return $minutes === 120 ?: "got {$minutes}";
});

check('a desk that is never open still produces a finite deadline', function () use ($plugin, $settings) {
    $settings->setBusinessHours([]);
    // An empty set falls back to nine-to-five, so blank every day explicitly.
    $reflection = new ReflectionProperty($settings, '_businessHours');
    $reflection->setAccessible(true);
    $reflection->setValue($settings, ['monday' => null]);
    $plugin->slas->refresh();

    $due = $plugin->slas->due(new DateTime('2026-03-02 10:00:00'), 60, true);
    $ok = $due > new DateTime('2026-03-02 09:00:00');

    $settings->setBusinessHours([
        'monday' => ['open' => '09:00', 'close' => '17:00'],
        'tuesday' => ['open' => '09:00', 'close' => '17:00'],
        'wednesday' => ['open' => '09:00', 'close' => '17:00'],
        'thursday' => ['open' => '09:00', 'close' => '17:00'],
        'friday' => ['open' => '09:00', 'close' => '17:00'],
    ]);
    $plugin->slas->refresh();

    return $ok;
});

// -----------------------------------------------------------------------------
section('The clock pausing');

check('going pending pauses it', function () use ($plugin, $siteId, $prefix, $elements, $policy) {
    $paused = $plugin->tickets->submit([
        'email' => 'pause@example.com',
        'subject' => $prefix . ' Waiting on them',
        'message' => 'x',
        'siteId' => $siteId,
        'requesterId' => null,
    ]);

    $pending = $plugin->statuses->getFirstOfKind(StatusKind::Pending);
    $plugin->tickets->setStatus($paused, (int)$pending->id, true, false);

    $ok = $paused->clockPausedAt !== null;
    $elements->deleteElement($paused, true);

    return $ok ?: 'the clock kept running while waiting on the customer';
});

check('a customer reply resumes it and takes the ticket out of pending', function () use ($plugin, $siteId, $prefix, $elements) {
    $paused = $plugin->tickets->submit([
        'email' => 'pause2@example.com',
        'subject' => $prefix . ' Resume me',
        'message' => 'x',
        'siteId' => $siteId,
        'requesterId' => null,
    ]);

    $pending = $plugin->statuses->getFirstOfKind(StatusKind::Pending);
    $plugin->tickets->setStatus($paused, (int)$pending->id, true, false);
    $plugin->replies->post($paused, ['body' => 'Here is the information you asked for.', 'byAgent' => false, 'notify' => false]);

    $ok = $paused->clockPausedAt === null && $paused->getTicketStatus()?->isOpen() === true;
    $elements->deleteElement($paused, true);

    return $ok ?: 'the clock stayed paused after the customer replied';
});

check('an SLA is applied to a new ticket', function () use ($plugin, $siteId, $prefix, $elements) {
    $timed = $plugin->tickets->submit([
        'email' => 'sla@example.com',
        'subject' => $prefix . ' Timed',
        'message' => 'x',
        'siteId' => $siteId,
        'requesterId' => null,
    ]);

    $ok = $timed->slaPolicyId !== null && $timed->firstResponseDue !== null;
    $elements->deleteElement($timed, true);

    return $ok ?: 'no policy claimed the ticket';
});

check('a ticket past its deadline reads as late without waiting for the sweep', function () use ($plugin, $siteId, $prefix, $elements) {
    $late = $plugin->tickets->submit([
        'email' => 'late@example.com',
        'subject' => $prefix . ' Late one',
        'message' => 'x',
        'siteId' => $siteId,
        'requesterId' => null,
    ]);

    $late->firstResponseDue = (new DateTime())->modify('-1 hour');
    Craft::$app->getElements()->saveElement($late, false);

    $ok = $late->getIsBreaching() === true;
    $elements->deleteElement($late, true);

    return $ok ?: 'a passed deadline did not show as late until the sweep ran';
});

check('the sweep stamps a breach exactly once', function () use ($plugin, $siteId, $prefix, $elements) {
    $late = $plugin->tickets->submit([
        'email' => 'sweep@example.com',
        'subject' => $prefix . ' Sweep me',
        'message' => 'x',
        'siteId' => $siteId,
        'requesterId' => null,
    ]);

    $late->firstResponseDue = (new DateTime())->modify('-2 hours');
    Craft::$app->getElements()->saveElement($late, false);

    $first = $plugin->slas->sweep(50);
    $second = $plugin->slas->sweep(50);

    $reloaded = Ticket::find()->id($late->id)->isSpam(null)->one();
    $breaches = 0;

    foreach ($plugin->tickets->getEvents((int)$late->id) as $event) {
        if ($event->type === 'sla_breached') {
            $breaches++;
        }
    }

    $ok = $reloaded->firstResponseBreached === true && $breaches === 1;
    $elements->deleteElement($late, true);

    return $ok ?: "breach events: {$breaches} (first sweep found {$first['firstResponse']}, second {$second['firstResponse']})";
});

check('a ticket somebody replied to is not swept as unanswered', function () use ($plugin, $siteId, $prefix, $elements) {
    $answered = $plugin->tickets->submit([
        'email' => 'answered@example.com',
        'subject' => $prefix . ' Answered in time',
        'message' => 'x',
        'siteId' => $siteId,
        'requesterId' => null,
    ]);

    $plugin->replies->post($answered, ['body' => 'On it.', 'byAgent' => true, 'notify' => false]);
    $answered->firstResponseDue = (new DateTime())->modify('-2 hours');
    Craft::$app->getElements()->saveElement($answered, false);

    $plugin->slas->sweep(50);

    $reloaded = Ticket::find()->id($answered->id)->isSpam(null)->one();
    $ok = $reloaded->firstResponseBreached === false;

    $elements->deleteElement($answered, true);

    return $ok ?: 'a ticket that was answered in time was reported as late';
});

check('SLAs are a Pro feature', function () use ($plugin) {
    $plugin->edition = Plugin::EDITION_LITE;
    $plugin->slas->refresh();

    $none = $plugin->slas->getAllPolicies() === [];
    $swept = $plugin->slas->sweep(10);

    $plugin->edition = Plugin::EDITION_PRO;
    $plugin->slas->refresh();

    return $none && array_sum($swept) === 0;
});

// -----------------------------------------------------------------------------
section('Deflection');

check('suggestions come back for a subject somebody is typing', function () use ($plugin, $siteId, $howToRefund) {
    $results = $plugin->deflection->suggest('charged twice on my card', null, $siteId);

    return in_array((int)$howToRefund->id, array_map(static fn($r) => (int)$r->article->id, $results), true);
});

check('nothing comes back before enough has been typed', function () use ($plugin, $siteId, $settings) {
    $settings->deflectionMinChars = 20;
    $results = $plugin->deflection->suggest('refund', null, $siteId);
    $settings->deflectionMinChars = 3;

    return $results === [];
});

check('the chosen category narrows the suggestions', function () use ($plugin, $siteId, $howToRefund, $refunds) {
    $results = $plugin->deflection->suggest('refund', (int)$refunds->id, $siteId);

    return in_array((int)$howToRefund->id, array_map(static fn($r) => (int)$r->article->id, $results), true);
});

check('a session is recorded once, not once per keystroke', function () use ($plugin, $siteId) {
    Db::delete(Table::DEFLECTIONS, ['siteId' => $siteId]);

    $results = $plugin->deflection->suggest('refund please', null, $siteId);
    $plugin->deflection->record('refund', $results, null, $siteId);
    $plugin->deflection->record('refund pl', $results, null, $siteId);
    $plugin->deflection->record('refund please', $results, null, $siteId);

    $rows = (int)(new craft\db\Query())->from([Table::DEFLECTIONS])->where(['siteId' => $siteId])->count();

    return $rows === 1 ?: "{$rows} rows for one visitor";
});

check('saying it answered the question is a deflection', function () use ($plugin, $siteId) {
    $plugin->deflection->recordDeflected(null, $siteId);

    $stats = $plugin->deflection->getStats(30, $siteId);

    return $stats['deflected'] === 1 && $stats['rate'] === 1.0;
});

check('writing in anyway is counted on the other side', function () use ($plugin, $siteId, $prefix, $elements) {
    Db::delete(Table::DEFLECTIONS, ['siteId' => $siteId]);

    $results = $plugin->deflection->suggest('refund', null, $siteId);
    $plugin->deflection->record('refund', $results, null, $siteId);

    $wroteIn = $plugin->tickets->submit([
        'email' => 'anyway@example.com',
        'subject' => $prefix . ' Wrote in anyway',
        'message' => 'x',
        'siteId' => $siteId,
        'requesterId' => null,
    ]);

    $stats = $plugin->deflection->getStats(30, $siteId);
    $ok = $stats['submitted'] === 1 && $wroteIn->deflectionId !== null;

    $elements->deleteElement($wroteIn, true);

    return $ok ?: json_encode($stats);
});

check('an abandoned session counts as neither', function () use ($plugin, $siteId) {
    Db::delete(Table::DEFLECTIONS, ['siteId' => $siteId]);

    $results = $plugin->deflection->suggest('refund', null, $siteId);
    $plugin->deflection->record('refund', $results, null, $siteId);

    // Age it past the session window, then close stale sessions the way garbage collection does.
    Db::update(Table::DEFLECTIONS, ['dateCreated' => Db::prepareDateForDb((new DateTime())->modify('-2 hours'))], ['siteId' => $siteId]);
    $plugin->deflection->closeStaleSessions();

    $stats = $plugin->deflection->getStats(30, $siteId);

    return $stats['abandoned'] === 1 && $stats['rate'] === null;
});

check('deflection analytics are a Pro feature but the panel is not', function () use ($plugin, $siteId) {
    $plugin->edition = Plugin::EDITION_LITE;

    $stats = $plugin->deflection->getStats(30, $siteId);
    $suggestions = $plugin->deflection->suggest('charged twice on my card', null, $siteId);

    $plugin->edition = Plugin::EDITION_PRO;

    return $stats['available'] === false && $suggestions !== [];
});

// -----------------------------------------------------------------------------
section('Canned replies');

check('a canned reply saves and renders against a ticket', function () use ($plugin, $ticket, $prefix) {
    $canned = new justinholtweb\csr\models\CannedReply([
        'name' => $prefix . ' Refund explanation',
        'shortcut' => '/' . $prefix . 'refund',
        'body' => 'Hi {requesterName}, ticket {ticketNumber} is about {ticketSubject}. — {agentName}',
    ]);

    if (!$plugin->cannedReplies->saveReply($canned)) {
        return json_encode($canned->getErrors());
    }

    $rendered = $canned->render($ticket);
    $ok = str_contains($rendered, 'Ada')
        && str_contains($rendered, (string)$ticket->number)
        && !str_contains($rendered, '{requesterName}');

    $plugin->cannedReplies->deleteReplyById((int)$canned->id);

    return $ok ?: $rendered;
});

check('an unknown placeholder is left visible rather than blanked', function () use ($ticket) {
    $canned = new justinholtweb\csr\models\CannedReply([
        'name' => 'x',
        'body' => 'Your total was {orderTotal}.',
    ]);

    return str_contains($canned->render($ticket), '{orderTotal}');
});

check('using one bumps its counter', function () use ($plugin, $ticket, $prefix) {
    $canned = new justinholtweb\csr\models\CannedReply([
        'name' => $prefix . ' Counted',
        'body' => 'Thanks for getting in touch.',
    ]);
    $plugin->cannedReplies->saveReply($canned);

    $plugin->replies->post($ticket, [
        'body' => 'Thanks for getting in touch.',
        'byAgent' => true,
        'cannedReplyId' => (int)$canned->id,
        'notify' => false,
    ]);

    $used = (int)(new craft\db\Query())->select(['useCount'])->from([Table::CANNED_REPLIES])->where(['id' => $canned->id])->scalar();
    $plugin->cannedReplies->deleteReplyById((int)$canned->id);

    return $used === 1 ?: "useCount was {$used}";
});

check('canned replies are a Pro feature', function () use ($plugin, $prefix) {
    $plugin->edition = Plugin::EDITION_LITE;
    $plugin->cannedReplies->refresh();

    $blocked = new justinholtweb\csr\models\CannedReply(['name' => $prefix . ' Lite', 'body' => 'x']);
    $refused = !$plugin->cannedReplies->saveReply($blocked);
    $empty = $plugin->cannedReplies->getAllReplies() === [];

    $plugin->edition = Plugin::EDITION_PRO;
    $plugin->cannedReplies->refresh();

    return $refused && $empty;
});

// -----------------------------------------------------------------------------
section('Spam');

check('the honeypot catches a filled hidden field', function () use ($plugin, $settings) {
    return $plugin->spam->check([$settings->honeypotField => 'http://spam.example'], 'x@example.com')
        === justinholtweb\csr\services\Spam::REASON_HONEYPOT;
});

check('an empty honeypot passes', function () use ($plugin, $settings) {
    return $plugin->spam->check([$settings->honeypotField => ''], 'x@example.com') === null;
});

check('a form completed too fast is refused', function () use ($plugin, $settings) {
    $settings->minSubmitSeconds = 5;

    $reason = $plugin->spam->check(['csrStamp' => $plugin->spam->stamp()], 'x@example.com');
    $settings->minSubmitSeconds = 0;

    return $reason === justinholtweb\csr\services\Spam::REASON_TOO_FAST;
});

check('a form with no stamp at all is refused when the check is on', function () use ($plugin, $settings) {
    $settings->minSubmitSeconds = 5;
    $reason = $plugin->spam->check([], 'x@example.com');
    $settings->minSubmitSeconds = 0;

    return $reason === justinholtweb\csr\services\Spam::REASON_TOO_FAST;
});

check('a back-dated stamp cannot be forged', function () use ($plugin, $settings) {
    $settings->minSubmitSeconds = 5;
    $reason = $plugin->spam->check(['csrStamp' => (string)(time() - 3600)], 'x@example.com');
    $settings->minSubmitSeconds = 0;

    return $reason === justinholtweb\csr\services\Spam::REASON_TOO_FAST;
});

check('an old enough stamp passes', function () use ($plugin, $settings) {
    $settings->minSubmitSeconds = 5;

    $stamp = Craft::$app->getSecurity()->hashData((string)(time() - 60));
    $reason = $plugin->spam->check(['csrStamp' => $stamp], 'x@example.com');

    $settings->minSubmitSeconds = 0;

    return $reason === null ?: $reason;
});

check('the rate limit counts per address, not per IP', function () use ($plugin, $settings, $siteId, $prefix, $elements) {
    $settings->rateLimit = 2;
    $made = [];

    for ($i = 0; $i < 2; $i++) {
        $made[] = $plugin->tickets->submit([
            'email' => 'flooder@example.com',
            'subject' => $prefix . " Flood {$i}",
            'message' => 'x',
            'siteId' => $siteId,
            'requesterId' => null,
        ]);
    }

    $blocked = $plugin->spam->check([], 'flooder@example.com');
    $somebodyElse = $plugin->spam->check([], 'innocent@example.com');

    $settings->rateLimit = 0;

    foreach ($made as $m) {
        $elements->deleteElement($m, true);
    }

    return $blocked === justinholtweb\csr\services\Spam::REASON_RATE_LIMIT && $somebodyElse === null;
});

check('every refusal says the same thing to the visitor', function () use ($plugin) {
    // Different reason, identical message: a form that tells a robot which check caught it is
    // telling it which one to work around.
    return $plugin->spam->refusalMessage() === $plugin->spam->refusalMessage();
});

check('a honeypot named after one of Craft’s own parameters is refused', function () use ($settings) {
    $clone = clone $settings;

    foreach (['token', 'p', 'action', 'siteId'] as $reserved) {
        $clone->honeypotField = $reserved;
        $clone->clearErrors();

        if ($clone->validate(['honeypotField'])) {
            return "“{$reserved}” was accepted";
        }
    }

    return true;
});

// -----------------------------------------------------------------------------
section('Merging, spam and satisfaction');

check('merging moves the replies and closes the duplicate', function () use ($plugin, $siteId, $prefix, $elements) {
    $keep = $plugin->tickets->submit(['email' => 'a@example.com', 'subject' => $prefix . ' Keep', 'message' => 'x', 'siteId' => $siteId, 'requesterId' => null]);
    $dupe = $plugin->tickets->submit(['email' => 'a@example.com', 'subject' => $prefix . ' Dupe', 'message' => 'x', 'siteId' => $siteId, 'requesterId' => null]);

    $plugin->replies->post($dupe, ['body' => 'A reply on the duplicate.', 'byAgent' => false, 'notify' => false]);

    $merged = $plugin->tickets->merge($dupe, $keep);

    $reloadedDupe = Ticket::find()->id($dupe->id)->isSpam(null)->one();
    $movedReplies = (int)(new craft\db\Query())->from([Table::REPLIES])->where(['ticketId' => $keep->id])->count();

    $ok = $merged
        && $movedReplies >= 1
        && (int)$reloadedDupe->mergedIntoId === (int)$keep->id
        && $reloadedDupe->getTicketStatus()?->isOpen() === false;

    $elements->deleteElement($dupe, true);
    $elements->deleteElement($keep, true);

    return $ok ?: 'the merge did not do all of it';
});

check('merging is a Pro feature', function () use ($plugin, $siteId, $prefix, $elements) {
    $a = $plugin->tickets->submit(['email' => 'a@example.com', 'subject' => $prefix . ' A', 'message' => 'x', 'siteId' => $siteId, 'requesterId' => null]);
    $b = $plugin->tickets->submit(['email' => 'a@example.com', 'subject' => $prefix . ' B', 'message' => 'x', 'siteId' => $siteId, 'requesterId' => null]);

    $plugin->edition = Plugin::EDITION_LITE;
    $refused = !$plugin->tickets->merge($a, $b);
    $plugin->edition = Plugin::EDITION_PRO;

    $elements->deleteElement($a, true);
    $elements->deleteElement($b, true);

    return $refused;
});

check('marking as spam takes a ticket out of the default index', function () use ($plugin, $siteId, $prefix, $elements) {
    $junk = $plugin->tickets->submit(['email' => 'junk@example.com', 'subject' => $prefix . ' Junk', 'message' => 'x', 'siteId' => $siteId, 'requesterId' => null]);
    $plugin->tickets->markAsSpam($junk);

    $visible = array_map('intval', Ticket::find()->limit(null)->ids());
    $inSpam = array_map('intval', Ticket::find()->isSpam(true)->limit(null)->ids());

    $ok = !in_array((int)$junk->id, $visible, true) && in_array((int)$junk->id, $inSpam, true);
    $elements->deleteElement($junk, true);

    return $ok;
});

check('a customer can rate a resolved ticket', function () use ($plugin, $ticket) {
    $plugin->tickets->rate($ticket, 5, 'Sorted quickly.');

    return $ticket->satisfaction === 5;
});

check('a rating outside one to five is clamped', function () use ($plugin, $ticket) {
    $plugin->tickets->rate($ticket, 99);

    return $ticket->satisfaction === 5;
});

check('satisfaction is a Pro feature', function () use ($plugin, $ticket) {
    $plugin->edition = Plugin::EDITION_LITE;
    $refused = !$plugin->tickets->rate($ticket, 1);
    $plugin->edition = Plugin::EDITION_PRO;

    return $refused;
});

// -----------------------------------------------------------------------------
section('Housekeeping');

check('resolved tickets are closed after the window', function () use ($plugin, $siteId, $prefix, $elements) {
    $old = $plugin->tickets->submit(['email' => 'old@example.com', 'subject' => $prefix . ' Old', 'message' => 'x', 'siteId' => $siteId, 'requesterId' => null]);

    $resolved = $plugin->statuses->getFirstOfKind(StatusKind::Resolved);
    $plugin->tickets->setStatus($old, (int)$resolved->id, true, false);

    $old->resolvedAt = (new DateTime())->modify('-30 days');
    Craft::$app->getElements()->saveElement($old, false);

    $closed = $plugin->tickets->closeResolved(7);

    $reloaded = Ticket::find()->id($old->id)->isSpam(null)->one();
    $ok = $closed >= 1 && $reloaded->getTicketStatus()?->getKind() === StatusKind::Closed;

    $elements->deleteElement($old, true);

    return $ok ?: "closed {$closed}";
});

check('a recently resolved ticket is left alone', function () use ($plugin, $siteId, $prefix, $elements) {
    $recent = $plugin->tickets->submit(['email' => 'recent@example.com', 'subject' => $prefix . ' Recent', 'message' => 'x', 'siteId' => $siteId, 'requesterId' => null]);

    $resolved = $plugin->statuses->getFirstOfKind(StatusKind::Resolved);
    $plugin->tickets->setStatus($recent, (int)$resolved->id, true, false);

    $plugin->tickets->closeResolved(7);

    $reloaded = Ticket::find()->id($recent->id)->isSpam(null)->one();
    $ok = $reloaded->getTicketStatus()?->getKind() === StatusKind::Resolved;

    $elements->deleteElement($recent, true);

    return $ok ?: 'a ticket resolved today was closed';
});

check('automatic closing can be switched off', function () use ($plugin) {
    return $plugin->tickets->closeResolved(0) === 0;
});

check('retention deletes nothing when no period is set', function () use ($plugin) {
    return $plugin->tickets->prune(0) === 0;
});

check('search rows past the window are pruned', function () use ($plugin, $siteId, $prefix) {
    $plugin->search->log($prefix . ' ancient', 0, $siteId);

    Db::update(
        Table::SEARCHES,
        ['dateCreated' => Db::prepareDateForDb((new DateTime())->modify('-400 days'))],
        ['normalizedQuery' => $plugin->search->normalize($prefix . ' ancient')],
    );

    $plugin->search->prune(365);

    return !(new craft\db\Query())
        ->from([Table::SEARCHES])
        ->where(['normalizedQuery' => $plugin->search->normalize($prefix . ' ancient')])
        ->exists();
});

check('Lite caps how much search history is kept', function () {
    return Edition::maxSearchRetentionDays(false) === Edition::LITE_SEARCH_RETENTION_DAYS
        && Edition::maxSearchRetentionDays(true) === null;
});

// -----------------------------------------------------------------------------
section('Reports');

check('the search totals add up', function () use ($plugin, $siteId) {
    $totals = $plugin->analytics->getSearchTotals(30, $siteId);

    return $totals['searches'] >= $totals['failed'] && $totals['failureRate'] !== null;
});

check('top searches come back in Lite as well as Pro', function () use ($plugin, $siteId) {
    $plugin->edition = Plugin::EDITION_LITE;
    $top = $plugin->analytics->getTopSearches(30, 10, $siteId);
    $failed = $plugin->analytics->getFailedSearches(30, 10, $siteId);
    $plugin->edition = Plugin::EDITION_PRO;

    return $top !== [] && $failed === [];
});

check('the desk summary reports the numbers it promises', function () use ($plugin, $siteId) {
    $summary = $plugin->analytics->getDeskSummary(30, $siteId);

    foreach (['raised', 'resolved', 'open', 'backlog', 'firstResponseBreaches', 'available'] as $key) {
        if (!array_key_exists($key, $summary)) {
            return "missing {$key}";
        }
    }

    return $summary['available'] === true && $summary['raised'] >= 1;
});

check('the ticket trend has a row for every day, including empty ones', function () use ($plugin, $siteId) {
    $trend = $plugin->analytics->getTicketTrend(7, $siteId);

    return count($trend) === 8;
});

check('tickets by category can be read against the article count', function () use ($plugin, $refunds) {
    $rows = $plugin->analytics->getTicketsByCategory(30);

    foreach ($rows as $row) {
        if ($row['categoryId'] === (int)$refunds->id) {
            return array_key_exists('articles', $row) && $row['tickets'] >= 1;
        }
    }

    return 'the refunds category is not in the report';
});

check('desk reports are a Pro feature', function () use ($plugin, $siteId) {
    $plugin->edition = Plugin::EDITION_LITE;
    $summary = $plugin->analytics->getDeskSummary(30, $siteId);
    $workload = $plugin->analytics->getAgentWorkload(30);
    $plugin->edition = Plugin::EDITION_PRO;

    return $summary['available'] === false && $workload === [];
});

check('the exporter is a Pro feature', function () use ($plugin) {
    $plugin->edition = Plugin::EDITION_LITE;
    $lite = $plugin->exporter->isAvailable();
    $plugin->edition = Plugin::EDITION_PRO;

    return $lite === false && $plugin->exporter->isAvailable() === true;
});

// -----------------------------------------------------------------------------
section('Structured data');

check('an article produces valid Article schema', function () use ($plugin, $howToRefund) {
    $schema = $plugin->schema->forArticle($howToRefund);

    return ($schema['@type'] ?? null) === 'Article'
        && ($schema['headline'] ?? null) === $howToRefund->title
        && isset($schema['publisher']);
});

check('breadcrumbs walk the help centre, the category and the page', function () use ($plugin, $howToRefund) {
    $schema = $plugin->schema->forBreadcrumbs($howToRefund);

    return $schema !== null && count($schema['itemListElement']) === 3;
});

check('FAQPage is emitted only when the articles really are questions', function () use ($plugin, $refunds, $howToRefund, $unfiled) {
    $questions = $plugin->schema->forFaq($refunds, [$howToRefund, $howToRefund]);
    $notQuestions = $plugin->schema->forFaq($refunds, [$unfiled, $unfiled]);

    return $questions !== null && $notQuestions === null;
});

check('a title that could break out of the script tag is escaped', function () use ($plugin, $elements, $siteId, $prefix) {
    $nasty = new Article([
        'siteId' => $siteId,
        'title' => $prefix . ' </script><script>alert(1)</script>',
        'slug' => $prefix . '-nasty',
        'enabled' => true,
    ]);
    $elements->saveElement($nasty);

    $rendered = $plugin->schema->render($plugin->schema->forArticle($nasty));
    $ok = !str_contains($rendered, '</script><script>');

    $elements->deleteElement($nasty, true);

    return $ok ?: 'the title escaped its own script tag';
});

// -----------------------------------------------------------------------------
section('Pigeon');

check('the bridge is a no-op when Pigeon is absent or switched off', function () use ($plugin, $ticket, $settings) {
    $settings->pigeonBridge = false;

    return $plugin->pigeon->isEnabled() === false
        && $plugin->pigeon->openThread($ticket) === false
        && $plugin->pigeon->getThreadUrl($ticket) === null;
});

check('CSR does not require Pigeon to be installed', function () use ($plugin) {
    // Whatever the answer is on this machine, asking must not throw.
    $installed = $plugin->pigeon->isInstalled();

    return is_bool($installed);
});

// -----------------------------------------------------------------------------
section('The control panel indexes');

/**
 * Walk every source, sort option and table column of both element types.
 *
 * Craft builds an index source's query with `Craft::configure()`, which assigns *straight to a
 * public property* when one exists rather than calling the setter of the same name. A query
 * parameter typed more narrowly than its setter accepts therefore raises "Cannot assign int to
 * property …" and takes the whole index down — for whichever source nobody happened to click while
 * testing. Walking them all is the only way to catch the class.
 */
foreach ([Article::class, Ticket::class] as $elementType) {
    $short = substr(strrchr($elementType, '\\'), 1);

    check("every {$short} index source runs", function () use ($elementType, $short) {
        foreach ($elementType::sources('index') as $source) {
            if (!isset($source['key'])) {
                continue;
            }

            $query = $elementType::find();
            Craft::configure($query, $source['criteria'] ?? []);

            try {
                $query->limit(1)->all();
            } catch (Throwable $e) {
                return "source “{$source['key']}”: " . $e->getMessage();
            }
        }

        return true;
    });

    check("every {$short} sort option runs", function () use ($elementType) {
        foreach ($elementType::sortOptions() as $key => $option) {
            $orderBy = is_array($option) ? ($option['orderBy'] ?? null) : (is_string($key) ? $key : null);

            // Craft allows a closure here (its own "Date Updated" uses one); only the plain
            // column names can be handed back to the query builder.
            if (!is_string($orderBy)) {
                continue;
            }

            try {
                $elementType::find()->orderBy([$orderBy => SORT_ASC])->limit(1)->all();
            } catch (Throwable $e) {
                return "sort “{$orderBy}”: " . $e->getMessage();
            }
        }

        return true;
    });
}

check('every ticket table column renders', function () use ($ticket) {
    $method = new ReflectionMethod($ticket, 'attributeHtml');
    $method->setAccessible(true);

    // `tableAttributes()` includes columns other plugins added through Craft's event. Only the
    // ones CSR declares are CSR's to render.
    $declared = new ReflectionMethod(Ticket::class, 'defineTableAttributes');
    $declared->setAccessible(true);

    foreach (array_keys($declared->invoke(null)) as $attribute) {
        try {
            $method->invoke($ticket, $attribute);
        } catch (Throwable $e) {
            return "column “{$attribute}”: " . $e->getMessage();
        }
    }

    return true;
});

check('every article table column renders', function () use ($howToRefund) {
    $method = new ReflectionMethod($howToRefund, 'attributeHtml');
    $method->setAccessible(true);

    $declared = new ReflectionMethod(Article::class, 'defineTableAttributes');
    $declared->setAccessible(true);

    foreach (array_keys($declared->invoke(null)) as $attribute) {
        try {
            $method->invoke($howToRefund, $attribute);
        } catch (Throwable $e) {
            return "column “{$attribute}”: " . $e->getMessage();
        }
    }

    return true;
});

check('a ticket loaded from the database has a property for every column it selects', function () use ($ticket) {
    // Craft hands the whole row to the element's constructor, so a stored column with neither a
    // property nor a setter throws `UnknownPropertyException` from `ElementQuery::createElement()`
    // — and only when the element is genuinely read from a row, never when it comes back out of
    // the identity map after a save. That hides it from exactly the tests most likely to be
    // written, so this one clears the cache first.
    Craft::$app->getElements()->invalidateCachesForElement($ticket);

    $reloaded = Ticket::find()->id($ticket->id)->isSpam(null)->one();

    return $reloaded !== null && $reloaded->number === $ticket->number;
});

check('an article loaded from the database does too', function () use ($howToRefund, $siteId) {
    Craft::$app->getElements()->invalidateCachesForElement($howToRefund);

    $reloaded = Article::find()->id($howToRefund->id)->siteId($siteId)->status(null)->one();

    return $reloaded !== null && $reloaded->keywords === $howToRefund->keywords;
});

check('the queue ordering puts urgent first, oldest first within that', function () use ($plugin, $siteId, $prefix, $elements, $settings) {
    $settings->allowPrioritySelection = true;

    $routine = $plugin->tickets->submit(['email' => 'q1@example.com', 'subject' => $prefix . ' Routine', 'message' => 'x', 'priority' => Priority::Low->value, 'siteId' => $siteId, 'requesterId' => null]);
    $urgent = $plugin->tickets->submit(['email' => 'q2@example.com', 'subject' => $prefix . ' Urgent', 'message' => 'x', 'priority' => Priority::Urgent->value, 'siteId' => $siteId, 'requesterId' => null]);

    $settings->allowPrioritySelection = false;

    $ordered = Ticket::find()
        ->id([(int)$routine->id, (int)$urgent->id])
        ->orderByQueue()
        ->ids();

    $ok = (int)$ordered[0] === (int)$urgent->id;

    $elements->deleteElement($routine, true);
    $elements->deleteElement($urgent, true);

    return $ok ?: 'the routine ticket came first';
});

// -----------------------------------------------------------------------------
section('Class hygiene');

/**
 * Every class loads, and nothing declares a method whose parent has a different signature.
 *
 * `Model::load()`, `Model::rules()`, `Model::toArray()`, `Element::getStatus()` and every public
 * method on `yii\base\Controller` are fixed signatures: redeclaring one is a **compile-time**
 * fatal the moment the class autoloads, nowhere near the call site, and in a plugin it disables
 * the whole plugin. Two of these were found this way rather than by running the code.
 */
check('every class loads and no signature collides with a parent', function () {
    $classes = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__, 2) . '/src'));

    foreach ($iterator as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $source = file_get_contents($file->getPathname());

        if (
            preg_match('/namespace\s+([^;]+);/', $source, $namespace)
            && preg_match('/^(?:final\s+)?(?:abstract\s+)?class\s+(\w+)/m', $source, $name)
        ) {
            $classes[] = trim($namespace[1]) . '\\' . $name[1];
        }
    }

    if (count($classes) < 50) {
        return 'only found ' . count($classes) . ' classes — the scan is wrong';
    }

    $problems = [];

    foreach ($classes as $class) {
        if (!class_exists($class)) {
            $problems[] = "could not load {$class}";
            continue;
        }

        $reflection = new ReflectionClass($class);

        foreach ($reflection->getMethods() as $method) {
            if ($method->getDeclaringClass()->getName() !== $class) {
                continue;
            }

            $parent = $reflection->getParentClass();

            while ($parent) {
                if ($parent->hasMethod($method->getName())) {
                    $inherited = $parent->getMethod($method->getName());
                    $mine = (string)($method->getReturnType() ?? '');
                    $theirs = (string)($inherited->getReturnType() ?? '');

                    if ($theirs !== '' && $mine !== $theirs) {
                        $problems[] = "{$class}::{$method->getName()}(): {$mine} vs {$theirs}";
                    }

                    break;
                }

                $parent = $parent->getParentClass();
            }
        }
    }

    return $problems === [] ? true : implode('; ', array_slice($problems, 0, 5));
});

check('every front-end and control panel template parses', function () {
    $view = Craft::$app->getView();
    $mode = $view->getTemplateMode();
    $view->setTemplateMode(craft\web\View::TEMPLATE_MODE_CP);

    $root = dirname(__DIR__, 2) . '/src/templates';
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
    $problems = [];
    $count = 0;

    foreach ($iterator as $file) {
        if ($file->getExtension() !== 'twig') {
            continue;
        }

        $count++;
        $name = 'csr/' . ltrim(str_replace([$root, '.twig'], '', $file->getPathname()), '/');

        try {
            // Compiling proves the syntax without needing every variable a render would want.
            $view->getTwig()->load($name);
        } catch (Twig\Error\SyntaxError $e) {
            $problems[] = "{$name}: {$e->getMessage()} (line {$e->getTemplateLine()})";
        } catch (Throwable) {
            // A missing variable or an unknown filter argument at *load* time is not a syntax
            // error; only compilation failures are being checked here.
        }
    }

    $view->setTemplateMode($mode);

    if ($count < 30) {
        return "only found {$count} templates";
    }

    return $problems === [] ? true : implode('; ', array_slice($problems, 0, 5));
});

// -----------------------------------------------------------------------------
section('Cleaning up');

foreach (Article::find()->title($prefix . '*')->siteId('*')->unique()->status(null)->all() as $article) {
    $elements->deleteElement($article, true);
}

foreach (Ticket::find()->isSpam(null)->limit(null)->all() as $t) {
    if (str_starts_with((string)$t->title, $prefix)) {
        $elements->deleteElement($t, true);
    }
}

foreach ($plugin->slas->getAllPolicies() as $p) {
    if (str_starts_with($p->handle, $prefix)) {
        $plugin->slas->deletePolicyById((int)$p->id);
    }
}

foreach ($plugin->forms->getAllFields() as $f) {
    if (str_starts_with($f->handle, $prefix)) {
        $plugin->forms->deleteFieldById((int)$f->id);
    }
}

foreach ($plugin->cannedReplies->getAllReplies() as $r) {
    if (str_starts_with($r->name, $prefix)) {
        $plugin->cannedReplies->deleteReplyById((int)$r->id);
    }
}

foreach ($plugin->categories->getAllCategories() as $c) {
    if (str_starts_with($c->handle, $prefix)) {
        $plugin->categories->deleteCategoryById((int)$c->id);
    }
}

Db::delete(Table::SEARCHES, ['like', 'normalizedQuery', $prefix . '%', false]);
Db::delete(Table::DEFLECTIONS, ['siteId' => $siteId]);

echo "\n";
echo $failed === 0
    ? "\033[32mAll {$passed} checks passed.\033[0m\n"
    : "\033[31m{$passed} passed, {$failed} failed.\033[0m\n";

exit($failed === 0 ? 0 : 1);
