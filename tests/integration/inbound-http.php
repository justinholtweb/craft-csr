<?php
/**
 * CSR inbound email over real HTTP and the real console: the webhook endpoints' security, and
 * `php craft csr/inbound/*`.
 *
 *     docker exec -w /var/www/html ddev-plugin-testing-web php /var/www/craft-csr/tests/integration/inbound-http.php
 *
 * The web request and the console command read settings from project config, so this — unlike
 * `inbound.php` — has to persist them: inbound email on, a desk address and Postmark credentials
 * (Mailgun and SendGrid deliberately left unset, to prove an unconfigured provider is refused).
 * The original settings are restored in a **fresh PHP process** at the end, because once a web
 * request has seen the change this process's copy of project config is stale.
 */

$root = '/var/www/html';
require $root . '/bootstrap.php';

/** @var craft\console\Application $app */
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';

require __DIR__ . '/_inbound-fixtures.php';

use craft\db\Query;
use justinholtweb\csr\elements\Ticket;
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
        $result = get_class($e) . ': ' . $e->getMessage();
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

if (!$plugin->isPro()) {
    echo "CSR must be on Pro in the harness.\n";
    exit(1);
}

$senders = ['pat@example.org', 'kay@example.net'];
$inboundFloor = (int)(new Query())->from([Table::INBOUND])->max('id');
$queueFloor = (int)(new Query())->from('{{%queue}}')->max('id');
$original = Craft::$app->getProjectConfig()->get('plugins.csr.settings') ?? [];
$stash = sys_get_temp_dir() . '/csr-inbound-http-settings.json';
file_put_contents($stash, json_encode($original));

$persist = static function(array $changes) use ($plugin): void {
    $settings = $plugin->getSettings()->toArray();
    Craft::$app->getPlugins()->savePluginSettings($plugin, array_merge($settings, $changes));
    Craft::$app->getProjectConfig()->saveModifiedConfigData();
    Craft::$app->getProjectConfig()->writeYamlFiles(true);
};

$base = 'http://127.0.0.1';
$client = Craft::createGuzzleClient([
    'base_uri' => $base . '/',
    'verify' => false,
    'http_errors' => false,
    'allow_redirects' => false,
    'timeout' => 60,
]);

$post = static fn(string $provider, string $body, array $options = []) => $client->post('actions/csr/inbound/' . $provider, array_merge([
    'headers' => ['Content-Type' => 'application/json'],
    'body' => $body,
], $options));

try {
    $persist([
        'inboundEnabled' => true,
        'inboundAddress' => FIXTURE_DESK,
        'postmarkUsername' => FIXTURE_POSTMARK_USER,
        'postmarkPassword' => FIXTURE_POSTMARK_PASSWORD,
        'mailgunSigningKey' => '',
        'sendgridPublicKey' => '',
        'sendgridUsername' => '',
        'sendgridPassword' => '',
        'inboundRateLimit' => 0,
        'notifyRequesterOnNew' => false,
        'notifyAgentsOnNew' => false,
    ]);

    echo "\nThe webhook endpoints\n";

    check('a correctly authenticated Postmark post is accepted — with no CSRF token, and stored', function() use ($post) {
        $response = $post('postmark', postmarkPayload(['Subject' => 'Over HTTP']), ['auth' => [FIXTURE_POSTMARK_USER, FIXTURE_POSTMARK_PASSWORD]]);
        $row = (new Query())->from([Table::INBOUND])->where(['subject' => 'Over HTTP'])->one();

        return $response->getStatusCode() === 200 && $row !== false && $row !== null
            ?: 'HTTP ' . $response->getStatusCode() . ' ' . substr((string)$response->getBody(), 0, 200);
    });

    check('no credentials: 401, nothing stored', function() use ($post) {
        $response = $post('postmark', postmarkPayload(['Subject' => 'No creds']));
        $stored = (new Query())->from([Table::INBOUND])->where(['subject' => 'No creds'])->exists();

        return $response->getStatusCode() === 401 && !$stored ?: 'HTTP ' . $response->getStatusCode();
    });

    check('wrong password: 401, nothing stored', function() use ($post) {
        $response = $post('postmark', postmarkPayload(['Subject' => 'Bad creds']), ['auth' => [FIXTURE_POSTMARK_USER, 'guess']]);
        $stored = (new Query())->from([Table::INBOUND])->where(['subject' => 'Bad creds'])->exists();

        return $response->getStatusCode() === 401 && !$stored ?: 'HTTP ' . $response->getStatusCode();
    });

    check('Mailgun with no signing key configured: 403, even with a "signed" post', function() use ($client) {
        $request = mailgunRequest(['subject' => 'Unconfigured'], key: '');
        $response = $client->post('actions/csr/inbound/mailgun', ['form_params' => $request->post]);

        return $response->getStatusCode() === 403 ?: 'HTTP ' . $response->getStatusCode();
    });

    check('SendGrid with nothing configured: 403', function() use ($client) {
        $response = $client->post('actions/csr/inbound/sendgrid', ['form_params' => ['from' => 'x@example.org', 'text' => 'hi']]);

        return $response->getStatusCode() === 403 ?: 'HTTP ' . $response->getStatusCode();
    });

    check('GET is refused', function() use ($client) {
        $code = $client->get('actions/csr/inbound/postmark')->getStatusCode();

        return in_array($code, [400, 405], true) ?: "HTTP $code";
    });

    check('an unknown provider action is a 404', function() use ($post) {
        $code = $post('sparkpost', '{}', ['auth' => [FIXTURE_POSTMARK_USER, FIXTURE_POSTMARK_PASSWORD]])->getStatusCode();

        return $code === 404 ?: "HTTP $code";
    });

    echo "\nThe console\n";

    $craft = static function(string $command): array {
        exec('cd /var/www/html && php craft ' . $command . ' 2>&1', $output, $code);

        return [$code, implode("\n", $output)];
    };

    check('csr/inbound/import --sync takes a raw .eml in and raises the ticket', function() use ($craft) {
        [$code, $out] = $craft('csr/inbound/import /var/www/craft-csr/tests/fixtures/inbound/new-html.eml --sync');
        $ticket = Ticket::find()->requesterEmail('kay@example.net')->isSpam(null)->one();

        return $code === 0 && str_contains($out, 'accepted') && $ticket?->source === 'email' ?: "exit $code: $out";
    });

    check('csr/inbound/import of the same message again reports a duplicate', function() use ($craft) {
        [$code, $out] = $craft('csr/inbound/import /var/www/craft-csr/tests/fixtures/inbound/new-html.eml --sync');

        return $code === 0 && str_contains($out, 'duplicate') ?: "exit $code: $out";
    });

    check('csr/inbound/poll without the imap extension says so and exits 78', function() use ($craft) {
        [$code, $out] = $craft('csr/inbound/poll');

        if (function_exists('imap_open')) {
            return true;
        }

        return $code === 78 && str_contains($out, 'imap extension') ?: "exit $code: $out";
    });

    check('csr/inbound/process handles the email the webhook queued', function() use ($craft) {
        [$code, $out] = $craft('csr/inbound/process');
        $row = (new Query())->from([Table::INBOUND])->where(['subject' => 'Over HTTP'])->one();

        return $code === 0 && $row['status'] === 'ticket' ?: "exit $code: $out / " . ($row['status'] ?? '?');
    });

    check('csr/inbound/log lists what arrived', function() use ($craft) {
        [$code, $out] = $craft('csr/inbound/log --limit=5');

        return $code === 0 && str_contains($out, 'kay@example.net') ?: "exit $code: $out";
    });

    echo "\nSwitched off\n";

    $persist(['inboundEnabled' => false]);

    check('with inbound email off, the endpoint is a 404 even with good credentials', function() use ($post) {
        $code = $post('postmark', postmarkPayload(['Subject' => 'While off']), ['auth' => [FIXTURE_POSTMARK_USER, FIXTURE_POSTMARK_PASSWORD]])->getStatusCode();

        return $code === 404 ?: "HTTP $code";
    });

    check('…and the console refuses with a configuration exit code', function() use ($craft) {
        [$code] = $craft('csr/inbound/import /var/www/craft-csr/tests/fixtures/inbound/new-html.eml');

        return $code === 78 ?: "exit $code";
    });
} finally {
    echo "\nCleaning up\n";

    foreach (Ticket::find()->isSpam(null)->requesterEmail($senders)->limit(null)->all() as $ticket) {
        Craft::$app->getElements()->deleteElement($ticket, true);
    }

    Craft::$app->getDb()->createCommand()->delete(Table::INBOUND, ['>', 'id', $inboundFloor])->execute();
    Craft::$app->getDb()->createCommand()->delete('{{%queue}}', ['>', 'id', $queueFloor])->execute();

    // A fresh process: this one's project config went stale the moment a web request read it.
    $restore = sys_get_temp_dir() . '/csr-inbound-http-restore.php';
    file_put_contents($restore, <<<'PHP'
<?php
require '/var/www/html/bootstrap.php';
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';
$settings = json_decode(file_get_contents($argv[1]), true);
$config = Craft::$app->getProjectConfig();
$config->set('plugins.csr.settings', $settings, 'Restore CSR settings after inbound-http.php');
$config->saveModifiedConfigData();
$config->writeYamlFiles(true);
echo "  settings restored\n";
PHP);
    passthru(PHP_BINARY . ' ' . escapeshellarg($restore) . ' ' . escapeshellarg($stash));
    @unlink($restore);
    @unlink($stash);
}

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
