<?php
/**
 * CSR GraphQL — articles and categories, executed for real.
 *
 *     docker exec -w /var/www/html ddev-plugin-testing-web php /var/www/craft-csr/tests/integration/gql.php
 *
 * Every check runs a query through `Craft::$app->getGql()->executeQuery()` against an in-memory
 * schema. One schema per PHP process: Craft's type registry does not survive a flush and a second
 * schema in the same process ("multiple types named QueryArgument"), so the script builds its
 * fixtures, then re-invokes itself once per schema. Every schema gets a fresh UUID and GraphQL
 * caching is off for the run — a cached "allowed" answer must never stand in for a "denied" one.
 *
 * The point of the suite is the visibility gate: restricted, disabled and trashed articles must
 * never come back, whatever the query asks for and whoever is signed in.
 */

$root = '/var/www/html';
require $root . '/bootstrap.php';

/** @var craft\console\Application $app */
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';

use craft\elements\User;
use craft\helpers\StringHelper;
use craft\models\GqlSchema;
use justinholtweb\csr\elements\Article;
use justinholtweb\csr\enums\Visibility;
use justinholtweb\csr\gql\Queries;
use justinholtweb\csr\models\Category;
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
Craft::$app->getConfig()->getGeneral()->enableGraphqlCaching = false;
$siteId = (int)Craft::$app->getSites()->getPrimarySite()->id;
$mode = $argv[1] ?? null;
$fixtureFile = sys_get_temp_dir() . '/csr-gql-fixtures.json';

// =================================================================================== child: one schema
if ($mode !== null) {
    $f = json_decode((string)file_get_contents($fixtureFile), true);

    $scope = match ($mode) {
        'none' => [],
        'articles' => [Queries::ARTICLES_SCOPE . ':read'],
        'categories' => [Queries::CATEGORIES_SCOPE . ':read'],
        // `elements.inactive` lets a schema ask for disabled and trashed elements at all — the
        // case where only CSR's own gate stands between the query and a disabled article.
        default => [Queries::ARTICLES_SCOPE . ':read', Queries::CATEGORIES_SCOPE . ':read', 'elements.inactive:read'],
    };
    $schema = new GqlSchema(['name' => "CSR $mode", 'scope' => $scope, 'uid' => StringHelper::UUID()]);
    $run = static fn(string $query, array $variables = []) => Craft::$app->getGql()->executeQuery($schema, $query, $variables);
    $ids = 'id: [' . implode(', ', $f['articles']) . ']';
    $titles = static fn(array $result, string $key = 'csrArticles') => array_map(static fn($row) => $row['title'], $result['data'][$key] ?? []);

    echo "\nSchema: $mode\n";

    if ($mode === 'none') {
        check('a schema without the CSR components has none of the queries', function() use ($run) {
            $result = $run('{ csrArticles { title } csrCategories { name } }');
            $data = $result['data'] ?? [];

            return !array_key_exists('csrArticles', $data) && !array_key_exists('csrCategories', $data) ?: json_encode($result);
        });

        check('and does not offer them in its schema', function() use ($schema) {
            Craft::$app->getGql()->setActiveSchema($schema);

            return Queries::getQueries() === [] ?: implode(', ', array_keys(Queries::getQueries()));
        });
    }

    if ($mode === 'categories') {
        check('a categories-only schema cannot query articles', function() use ($run, $ids) {
            // graphql-php may throw while validating arguments of a field the schema lacks; either
            // way, no article data comes back.
            try {
                $result = $run("{ csrArticles($ids) { title } }");
            } catch (TypeError) {
                return true;
            }

            return !array_key_exists('csrArticles', $result['data'] ?? []) ?: json_encode($result);
        });

        check('enabled categories only — a disabled one never appears', function() use ($run, $f) {
            $result = $run('{ csrCategories { handle } }');
            $handles = array_column($result['data']['csrCategories'] ?? [], 'handle');

            return in_array($f['category'], $handles, true) && !in_array($f['disabledCategory'], $handles, true) ?: json_encode($handles);
        });

        check('one category by handle, its children, and a public-only article count', function() use ($run, $f) {
            $result = $run('query($h: String) { csrCategory(handle: $h) { name handle children { handle } articleCount } }', ['h' => $f['category']]);
            $c = $result['data']['csrCategory'] ?? null;

            // Two public articles are filed under it; the restricted and disabled ones are not counted.
            return $c !== null && $c['articleCount'] === 2 && array_column($c['children'], 'handle') === [$f['child']]
                ?: json_encode($result);
        });

        check('a disabled category by handle is null', function() use ($run, $f) {
            $result = $run('query($h: String) { csrCategory(handle: $h) { name } }', ['h' => $f['disabledCategory']]);

            return array_key_exists('csrCategory', $result['data'] ?? []) && $result['data']['csrCategory'] === null ?: json_encode($result);
        });
    }

    if ($mode === 'articles' || $mode === 'both') {
        check('public, enabled articles come back; restricted, group-only and disabled ones do not', function() use ($run, $ids, $titles, $f) {
            $got = $titles($run("{ csrArticles($ids) { title } }"));
            sort($got);

            return $got === $f['publicTitles'] ?: json_encode($got);
        });

        if ($mode === 'both') {
            check('with inactive elements allowed, status: disabled still does not widen the gate', function() use ($run, $ids, $titles) {
                $result = $run("{ disabledToo: csrArticles($ids, status: [\"disabled\", \"enabled\"]) { title } }");
                $got = $titles($result, 'disabledToo');

                return !isset($result['errors']) && !in_array('gql Disabled', $got, true) && $got !== [] ?: json_encode($result);
            });

            check('…and trashed: true returns nothing trashed', function() use ($run, $ids, $titles) {
                $result = $run("{ trashedToo: csrArticles($ids, trashed: true) { title } }");
                $got = $titles($result, 'trashedToo');

                return !isset($result['errors']) && !in_array('gql Trashed', $got, true) ?: json_encode($result);
            });
        }

        check('an admin signed in changes nothing: the gate is “anonymous visitor”, always', function() use ($run, $ids, $titles, $f) {
            Craft::$app->getUser()->setIdentity(User::find()->admin(true)->one());

            try {
                $got = $titles($run("{ asAdmin: csrArticles($ids) { title } }"), 'asAdmin');
            } finally {
                Craft::$app->getUser()->setIdentity(null);
            }

            sort($got);

            return $got === $f['publicTitles'] ?: json_encode($got);
        });

        check('a restricted article by id is null, a public one is returned', function() use ($run, $f) {
            $restricted = $run('query($id: [QueryArgument]) { csrArticle(id: $id) { title } }', ['id' => [$f['restricted']]]);
            $public = $run('query($id: [QueryArgument]) { one: csrArticle(id: $id) { title } }', ['id' => [$f['public']]]);

            return array_key_exists('csrArticle', $restricted['data'] ?? []) && $restricted['data']['csrArticle'] === null
                && ($public['data']['one']['title'] ?? '') === 'gql Public one'
                ?: json_encode([$restricted, $public]);
        });

        check('csrArticleCount counts what csrArticles returns', function() use ($run, $ids, $f) {
            $result = $run("{ csrArticleCount($ids) }");

            return ($result['data']['csrArticleCount'] ?? -1) === count($f['publicTitles']) ?: json_encode($result);
        });

        check('fields: url, excerpt, keywords, featured, and the category filter by handle', function() use ($run, $ids, $f) {
            $result = $run("query(\$c: [String]) { byCat: csrArticles($ids, category: \$c) { title url excerpt keywords featured categoryId } }", ['c' => [$f['category']]]);
            $rows = $result['data']['byCat'] ?? [];
            $first = $rows[0] ?? [];

            return count($rows) === 2 && str_contains((string)$first['url'], 'gql-public') && $first['excerpt'] === 'Public excerpt'
                && $first['categoryId'] === $f['categoryId'] && is_bool($first['featured'])
                ?: json_encode($result);
        });

        check('relatedArticles leaves out a hand-picked restricted article', function() use ($run, $f) {
            $result = $run('query($id: [QueryArgument]) { rel: csrArticle(id: $id) { relatedArticles { title } } }', ['id' => [$f['public']]]);
            $related = array_column($result['data']['rel']['relatedArticles'] ?? [], 'title');

            return in_array('gql Public two', $related, true) && !in_array('gql Logged-in only', $related, true) ?: json_encode($result);
        });

        check('the type is CsrArticle, implementing CsrArticleInterface', function() use ($run, $f) {
            $result = $run('query($id: [QueryArgument]) { t: csrArticle(id: $id) { __typename ... on CsrArticleInterface { id } } }', ['id' => [$f['public']]]);

            return ($result['data']['t']['__typename'] ?? '') === 'CsrArticle' ?: json_encode($result);
        });
    }

    if ($mode === 'articles') {
        check('an articles-only schema cannot query categories', function() use ($run) {
            $result = $run('{ csrCategories { name } }');

            return !array_key_exists('csrCategories', $result['data'] ?? []) ?: json_encode($result);
        });
    }

    if ($mode === 'both') {
        check('an article’s category resolves; with the knowledge base switched off, nothing does', function() use ($run, $plugin, $f) {
            $on = $run('query($id: [QueryArgument]) { a: csrArticle(id: $id) { category { handle } } }', ['id' => [$f['public']]]);
            $plugin->getSettings()->kbEnabled = false;

            try {
                $off = $run('query($id: [QueryArgument]) { b: csrArticle(id: $id) { title } c: csrCategories { handle } }', ['id' => [$f['public']]]);
            } finally {
                $plugin->getSettings()->kbEnabled = true;
            }

            return ($on['data']['a']['category']['handle'] ?? '') === $f['category']
                && !isset($off['errors']) && array_key_exists('b', $off['data'] ?? []) && $off['data']['b'] === null
                && ($off['data']['c'] ?? 'x') === []
                ?: json_encode([$on, $off]);
        });
    }

    echo "RESULT {$passed} {$failed}\n";
    exit(0);
}

// =================================================================================== parent: fixtures
$elements = Craft::$app->getElements();

foreach (Article::find()->title('gql *')->siteId('*')->unique()->status(null)->trashed(null)->all() as $stale) {
    $elements->deleteElement($stale, true);
}

foreach ($plugin->categories->getAllCategories() as $stale) {
    if (str_starts_with($stale->handle, 'gqlCat')) {
        $plugin->categories->deleteCategoryById((int)$stale->id);
    }
}

$suffix = substr(md5((string)microtime(true)), 0, 5);
$category = new Category(['name' => 'gql Cat', 'handle' => 'gqlCat' . $suffix]);
$plugin->categories->saveCategory($category);
$child = new Category(['name' => 'gql Child', 'handle' => 'gqlCatChild' . $suffix, 'parentId' => $category->id]);
$plugin->categories->saveCategory($child);
$disabled = new Category(['name' => 'gql Off', 'handle' => 'gqlCatOff' . $suffix, 'enabled' => false]);
$plugin->categories->saveCategory($disabled);

$article = static function(string $title, array $attributes = []) use ($elements, $siteId, $category): Article {
    $article = new Article(array_merge([
        'siteId' => $siteId,
        'title' => $title,
        'slug' => StringHelper::toKebabCase($title) . '-' . substr(md5($title . microtime()), 0, 4),
        'categoryId' => $category->id,
        'excerpt' => 'Public excerpt',
        'keywords' => 'gql, public',
        'enabled' => true,
    ], $attributes));

    if (!$elements->saveElement($article)) {
        throw new RuntimeException('Article did not save: ' . json_encode($article->getErrors()));
    }

    return $article;
};

$public = $article('gql Public one');
$publicTwo = $article('gql Public two', ['categoryId' => $child->id]);
$users = $article('gql Logged-in only', ['visibility' => Visibility::Users->value]);
$group = User::find()->admin(false)->one()?->getGroups()[0] ?? Craft::$app->getUserGroups()->getAllGroups()[0] ?? null;
$groups = $article('gql Group only', ['visibility' => Visibility::Groups->value, 'allowedGroupIds' => $group !== null ? [(int)$group->id] : [1]]);
$off = $article('gql Disabled', ['enabled' => false]);
$trashed = $article('gql Trashed');
$elements->deleteElement($trashed);

$plugin->articles->saveRelated((int)$public->id, [(int)$publicTwo->id, (int)$users->id]);

file_put_contents($fixtureFile, json_encode([
    'articles' => array_map(static fn(Article $a) => (int)$a->id, [$public, $publicTwo, $users, $groups, $off, $trashed]),
    'public' => (int)$public->id,
    'restricted' => (int)$users->id,
    'publicTitles' => ['gql Public one', 'gql Public two'],
    'category' => $category->handle,
    'categoryId' => (int)$category->id,
    'child' => $child->handle,
    'disabledCategory' => $disabled->handle,
]));

echo "CSR GraphQL checks\n";

try {
    foreach (['none', 'categories', 'articles', 'both'] as $schemaMode) {
        $output = [];
        exec(PHP_BINARY . ' ' . escapeshellarg(__FILE__) . ' ' . $schemaMode . ' 2>&1', $output);

        foreach ($output as $line) {
            if (preg_match('/^RESULT (\d+) (\d+)$/', $line, $m) === 1) {
                $passed += (int)$m[1];
                $failed += (int)$m[2];
                continue;
            }

            echo $line . "\n";
        }

        if (!preg_grep('/^RESULT /', $output)) {
            $failed++;
            echo "  ✗ the $schemaMode schema run did not finish\n";
        }
    }
} finally {
    foreach ([$public, $publicTwo, $users, $groups, $off] as $a) {
        $elements->deleteElement($a, true);
    }

    $trashedNow = Article::find()->id($trashed->id)->siteId('*')->status(null)->trashed(true)->one();

    if ($trashedNow !== null) {
        $elements->deleteElement($trashedNow, true);
    }

    foreach ([$child, $category, $disabled] as $c) {
        $plugin->categories->deleteCategoryById((int)$c->id);
    }

    @unlink($fixtureFile);
}

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
