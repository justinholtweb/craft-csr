<?php
/**
 * CSR screen checks — every page, over real HTTP.
 *
 * Run inside the plugin-testing container, from the site root:
 *
 *     docker exec -w /var/www/html ddev-plugin-testing-web php /var/www/craft-csr/tests/integration/screens.php
 *
 * The companion to `checks.php`, and it exists because of a bug that suite could not catch:
 * `{{ status.kind.label }}` **compiles** perfectly and then throws at render time, because Twig
 * resolves `kind` to the string column before it would ever try `getKind()`. Compiling a template
 * proves its syntax; only rendering one proves it works.
 *
 * Every control panel screen and every front-end route is fetched and asserted to return 200, with
 * a marker checked in the body so that a page which renders Craft's error template with a 200 —
 * or an empty shell — still fails.
 *
 * Needs a control panel login. The harness credentials are local-only and Justin authorised their
 * use for exactly this.
 */

$root = '/var/www/html';
require $root . '/bootstrap.php';

/** @var craft\console\Application $app */
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';

use GuzzleHttp\Cookie\CookieJar;
use justinholtweb\csr\elements\Article;
use justinholtweb\csr\enums\TicketSource;
use justinholtweb\csr\Plugin;

$passed = 0;
$failed = 0;

function screen(string $label, callable $test): void
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
        echo "  ✗ $label\n    " . (is_string($result) ? $result : var_export($result, true)) . "\n";
    } catch (Throwable $e) {
        $failed++;
        echo "  ✗ $label\n    " . get_class($e) . ': ' . $e->getMessage() . "\n";
    }
}

$plugin = Plugin::getInstance();

if ($plugin === null) {
    echo "CSR is not installed in this environment.\n";
    exit(1);
}

$base = rtrim((string)Craft::$app->getSites()->getPrimarySite()->getBaseUrl(), '/');
$jar = new CookieJar();

$client = Craft::createGuzzleClient([
    'base_uri' => $base . '/',
    'cookies' => $jar,
    'verify' => false,
    'http_errors' => false,
    // Generous: a control panel page in a harness with a hundred plugins installed can be close
    // to a megabyte, and a timeout here reads as a broken screen rather than a slow one.
    'timeout' => 180,
    'allow_redirects' => true,
]);

/** @return array{0: int, 1: string} */
function fetch($client, string $path, array $options = []): array
{
    $response = $client->request($options['method'] ?? 'GET', ltrim($path, '/'), $options['guzzle'] ?? []);

    return [$response->getStatusCode(), (string)$response->getBody()];
}

// Log in.
[$code, $body] = fetch($client, 'admin/login');
preg_match('/name="CRAFT_CSRF_TOKEN" value="([^"]+)"/', $body, $match);
$csrf = $match[1] ?? '';

$login = $client->post('index.php', [
    'headers' => ['Accept' => 'application/json', 'X-Requested-With' => 'XMLHttpRequest'],
    'form_params' => [
        'action' => 'users/login',
        'CRAFT_CSRF_TOKEN' => $csrf,
        'loginName' => getenv('CSR_TEST_USER') ?: 'admin',
        'password' => getenv('CSR_TEST_PASSWORD') ?: 'claudepassword',
    ],
]);

$loggedIn = str_contains((string)$login->getBody(), '"user"');

echo "CSR screen checks — {$base}\n";

if (!$loggedIn) {
    echo "\nCould not sign in to the control panel. Set CSR_TEST_USER and CSR_TEST_PASSWORD.\n";
    exit(1);
}

// A demo article, so the pages have something on them. Removed at the end.
$siteId = (int)Craft::$app->getSites()->getPrimarySite()->id;
$category = $plugin->categories->getTree()[0] ?? null;

$article = Article::find()->slug('csr-screen-check')->siteId($siteId)->status(null)->one();

if ($article === null) {
    $article = new Article([
        'siteId' => $siteId,
        'title' => 'Screen check article',
        'slug' => 'csr-screen-check',
        'categoryId' => $category?->id,
        'excerpt' => 'A page that exists so the screen checks have something to render.',
        'keywords' => 'screencheckphrase',
        'featured' => true,
        'enabled' => true,
    ]);
    Craft::$app->getElements()->saveElement($article);
    $createdArticle = true;
} else {
    $createdArticle = false;
}

$isPro = $plugin->isPro();

$ticket = $plugin->tickets->submit([
    'email' => 'screens@example.com',
    'name' => 'Screen Check',
    'subject' => 'Screen check ticket',
    'message' => 'Raised by the screen checks.',
    'categoryId' => $category?->id,
    'source' => TicketSource::ControlPanel,
    'siteId' => $siteId,
    'requesterId' => null,
]);

// -----------------------------------------------------------------------------
echo "\nControl panel\n";

$cpScreens = [
    ['csr/tickets', 'Tickets'],
    ['csr/tickets/new', 'Subject'],
    ['csr/tickets/' . $ticket->id, 'Screen check ticket'],
    ['csr/articles', 'Articles'],
    ['csr/articles/new', 'Also found by'],
    ['csr/articles/' . $article->id, 'Screen check article'],
    ['csr/categories', 'Categories'],
    ['csr/categories/new', 'Handle'],
    ['csr/reports', 'Reports'],
    ['csr/reports/searches', 'Came back with nothing'],
    ['csr/reports/articles', 'Articles'],
    // On Lite this screen deliberately shows what Pro would add instead of the report, so the
    // marker has to follow the edition rather than assume the paywall is open.
    ['csr/reports/desk', $isPro ? 'Promises kept' : 'Desk reports'],
    ['csr/reports/deflection', 'Deflection'],
    ['csr/settings/general', 'help centre is called'],
    ['csr/settings/knowledge-base', 'Was this helpful'],
    ['csr/settings/sites', 'URI format'],
    ['csr/settings/support-form', 'answer before the form'],
    ['csr/settings/notifications', 'Notifications'],
    ['csr/settings/spam', 'Honeypot'],
    ['csr/settings/article-fields', 'Article fields'],
    ['csr/settings/ticket-fields', 'Ticket fields'],
    ['csr/settings/statuses', 'Ticket statuses'],
    ['csr/settings/statuses/new', 'What it means'],
];

if ($category !== null) {
    $cpScreens[] = ['csr/categories/' . $category->id, $category->name];
}

// Pro-only screens 403 on Lite by design, so only walk them when they should be reachable.
if ($isPro) {
    $cpScreens[] = ['csr/canned-replies', 'Canned replies'];
    $cpScreens[] = ['csr/canned-replies/new', 'Available placeholders'];
    $cpScreens[] = ['csr/settings/teams', 'Teams'];
    $cpScreens[] = ['csr/settings/teams/new', 'Shared address'];
    $cpScreens[] = ['csr/settings/slas', 'Service levels'];
    $cpScreens[] = ['csr/settings/slas/new', 'What is promised'];
    $cpScreens[] = ['csr/settings/form-fields', 'Form questions'];
    $cpScreens[] = ['csr/settings/form-fields/new', 'The question'];
}

foreach ($cpScreens as [$path, $marker]) {
    screen($path, function () use ($client, $path, $marker) {
        [$code, $body] = fetch($client, 'admin/' . $path);

        if ($code !== 200) {
            // Craft's error page carries the message; pull it out rather than dumping the page.
            preg_match('/<h1[^>]*>(.*?)<\/h1>/s', $body, $heading);

            return "HTTP {$code}" . (isset($heading[1]) ? ': ' . trim(strip_tags($heading[1])) : '');
        }

        // A page can answer 200 and still be Craft's exception template, or an empty shell.
        if (!str_contains($body, $marker)) {
            return "200 but “{$marker}” is not on the page";
        }

        return true;
    });
}

screen('a Pro-only screen is refused on Lite', function () use ($client, $plugin, $isPro) {
    if ($isPro) {
        return true;
    }

    [$code] = fetch($client, 'admin/csr/canned-replies');

    return $code === 403 ?: "expected 403 on Lite, got {$code}";
});

// -----------------------------------------------------------------------------
echo "\nThe help centre\n";

$base = trim((string)$plugin->articles->getBaseUri($siteId), '/');
$support = trim($plugin->getSettings()->supportPath, '/');

$frontScreens = [
    [$base, 'How can we help'],
    [$base . '/' . $article->slug, 'Was this helpful'],
    [$base . '/search?q=screencheckphrase', 'Screen check article'],
    [$base . '/search?q=nothingwillevermatchthis', 'Nothing matched'],
    [$support, 'Get in touch'],
];

if ($category !== null) {
    $frontScreens[] = [$base . '/category/' . $category->handle, $category->name];
}

foreach ($frontScreens as [$path, $marker]) {
    screen('/' . $path, function () use ($client, $path, $marker) {
        [$code, $body] = fetch($client, $path);

        if ($code !== 200) {
            return "HTTP {$code}";
        }

        return str_contains($body, $marker) ?: "200 but “{$marker}” is not on the page";
    });
}

screen('the customer’s own ticket page opens with its reference', function () use ($client, $plugin, $ticket) {
    $path = trim($plugin->getSettings()->portalPath, '/') . '?csrRef=' . $ticket->reference;
    [$code, $body] = fetch($client, $path);

    return $code === 200 && str_contains($body, 'Screen check ticket')
        ?: "HTTP {$code}";
});

screen('and 404s on a reference that is not real', function () use ($client, $plugin) {
    $path = trim($plugin->getSettings()->portalPath, '/') . '?csrRef=' . str_repeat('a', 32);
    [$code, $body] = fetch($client, $path);

    // A bare `=== 404` is not a safe assertion in a shared harness: CSR raises
    // `NotFoundHttpException` correctly, and *another* installed plugin can then fatal inside
    // Craft's own 404 handling, turning the response into a 500 that says so. What matters here
    // is that CSR refused — so the check accepts either, and insists the refusal is a not-found
    // rather than something else.
    if ($code === 404) {
        return true;
    }

    return str_contains($body, 'NotFoundHttpException')
        ?: "expected a not-found, got {$code}";
});

screen('an internal note never reaches it', function () use ($client, $plugin, $ticket) {
    $plugin->replies->post($ticket, [
        'body' => 'NOTEMARKERdoNotShowThis',
        'isNote' => true,
        'byAgent' => true,
        'notify' => false,
    ]);

    $path = trim($plugin->getSettings()->portalPath, '/') . '?csrRef=' . $ticket->reference;
    [, $body] = fetch($client, $path);

    return !str_contains($body, 'NOTEMARKERdoNotShowThis')
        ?: 'the note is on the customer’s page';
});

// -----------------------------------------------------------------------------
echo "\nWhat the browser calls\n";

screen('the suggestions endpoint answers with JSON', function () use ($client, $article) {
    [$code, $body] = fetch($client, 'index.php?p=actions/csr/support/suggest&text=screencheckphrase', [
        'guzzle' => ['headers' => ['Accept' => 'application/json']],
    ]);

    $data = json_decode($body, true);

    return $code === 200
        && isset($data['results'])
        && in_array((int)$article->id, array_map(static fn($r) => (int)$r['id'], $data['results']), true)
        ?: "HTTP {$code}: " . substr($body, 0, 120);
});

screen('the live search endpoint answers with JSON', function () use ($client) {
    [$code, $body] = fetch($client, 'index.php?p=actions/csr/kb/suggest&q=screencheckphrase', [
        'guzzle' => ['headers' => ['Accept' => 'application/json']],
    ]);

    $data = json_decode($body, true);

    return $code === 200 && isset($data['results']) && $data['results'] !== []
        ?: "HTTP {$code}: " . substr($body, 0, 120);
});

// -----------------------------------------------------------------------------
Craft::$app->getElements()->deleteElement($ticket, true);

if ($createdArticle) {
    Craft::$app->getElements()->deleteElement($article, true);
}

echo "\n";
echo $failed === 0
    ? "\033[32mAll {$passed} screens rendered.\033[0m\n"
    : "\033[31m{$passed} rendered, {$failed} failed.\033[0m\n";

exit($failed === 0 ? 0 : 1);
