<?php
/**
 * Status letters render in Craft's Twig sandbox, and the control panel's templates follow Craft's
 * conventions — checked in the plugin-testing harness, partly over HTTP.
 *
 *     docker exec -w /var/www/html ddev-plugin-testing-web php /var/www/craft-csr/tests/integration/security.php
 *
 * Until 5.0.1: a status's own email subject and body were rendered with renderString(), which hands
 * the template the whole Craft API — `craft.app` included — every time a ticket changed status; and
 * the CP templates hand-rolled their `action` inputs and carried 100-odd inline styles that could
 * not follow the control panel.
 *
 * Nothing is sent and nothing is saved: the letter is set on the in-memory status, the mailer is
 * intercepted, and the harness's `enableTwigSandbox` is on (the sandbox's own precondition).
 */

$root = getcwd();
require $root . '/bootstrap.php';

/** @var craft\console\Application $app */
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';

use craft\mail\Mailer;
use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use justinholtweb\csr\elements\Ticket;
use justinholtweb\csr\Plugin;
use justinholtweb\csr\web\assets\cp\CpAsset;
use justinholtweb\csr\widgets\DeskWidget;
use yii\base\Event;
use yii\mail\MailEvent;

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

Craft::$app->getPlugins()->loadPlugins();
$plugin = Plugin::getInstance();
$src = dirname(__DIR__, 2) . '/src';

// Every message the mailer is asked to send, kept and not sent.
$sent = [];
Event::on(Mailer::class, Mailer::EVENT_BEFORE_SEND, static function(MailEvent $event) use (&$sent) {
    $sent[] = $event->message;
    $event->isValid = false;
});

echo "\nStatus letters\n";

$ticket = Ticket::find()->status(null)->andWhere(['not', ['csr_tickets.requesterEmail' => '']])->one();
$status = $ticket !== null ? ($plugin->statuses->getAllStatuses()[0] ?? null) : null;

check('the harness sandboxes (enableTwigSandbox is on) and has a ticket and a status to use', function() use ($ticket, $status) {
    return Craft::$app->getConfig()->getGeneral()->enableTwigSandbox && $ticket !== null && $status !== null
        ?: 'missing precondition';
});

$letter = static function(string $subject, string $body) use ($plugin, $ticket, $status, &$sent): array {
    $status->emailSubject = $subject;
    $status->emailBody = $body;
    $sent = [];
    $plugin->notifications->deliver($ticket, 'status', ['statusId' => $status->id]);

    return $sent;
};

[$originalSubject, $originalBody] = [$status?->emailSubject, $status?->emailBody];

try {
    check('a letter that reaches for craft.app is refused, not sent', function() use ($letter) {
        $sent = $letter('Ticket {{ number }}', 'Key: {{ craft.app.config.general.securityKey }}');

        return $sent === [] ?: 'sent: ' . $sent[0]->getSubject();
    });

    check('…including from the subject line', function() use ($letter) {
        $sent = $letter('{{ craft.app.users.getUserById(1).email }}', 'Hello');

        return $sent === [] ?: 'sent with subject ' . $sent[0]->getSubject();
    });

    check('an ordinary letter still renders the ticket’s variables and is sent', function() use ($letter, $ticket) {
        $sent = $letter('Ticket #{{ number }}: {{ statusName|upper }}', '<p>Hi {{ requesterName }}, about “{{ subject }}”.</p>');

        if (count($sent) !== 1) {
            return 'sent ' . count($sent);
        }

        $html = (string)$sent[0]->getSymfonyEmail()->getHtmlBody();

        return str_starts_with((string)$sent[0]->getSubject(), "Ticket #{$ticket->number}: ")
            && str_contains($html, $ticket->getGreetingName())
            ?: 'subject ' . $sent[0]->getSubject();
    });
} finally {
    if ($status !== null) {
        $status->emailSubject = $originalSubject;
        $status->emailBody = $originalBody;
    }
}

echo "\nControl panel templates\n";

$cpTemplates = array_filter(
    iterator_to_array(new RecursiveIteratorIterator(new RecursiveDirectoryIterator("$src/templates", FilesystemIterator::SKIP_DOTS))),
    static fn(SplFileInfo $f) => $f->getExtension() === 'twig' && !preg_match('#/templates/_(kb|portal|support)/#', $f->getPathname()),
);

check('no inline styles in the control panel templates', function() use ($cpTemplates, $src) {
    $found = [];
    foreach ($cpTemplates as $file) {
        if (preg_match_all('/\sstyle="/', file_get_contents($file->getPathname()), $m)) {
            $found[] = str_replace("$src/templates/", '', $file->getPathname()) . ' (' . count($m[0]) . ')';
        }
    }

    return $found === [] ?: implode(', ', $found);
});

check('no inline event handlers in the control panel templates', function() use ($cpTemplates, $src) {
    $found = [];
    foreach ($cpTemplates as $file) {
        if (preg_match('/<[^>]+\son(change|click|submit|input)="/i', file_get_contents($file->getPathname()))) {
            $found[] = str_replace("$src/templates/", '', $file->getPathname());
        }
    }

    return $found === [] ?: implode(', ', $found);
});

check('no hand-rolled action inputs anywhere — actionInput() instead', function() use ($src) {
    $found = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator("$src/templates", FilesystemIterator::SKIP_DOTS)) as $file) {
        if ($file->getExtension() === 'twig' && str_contains(file_get_contents($file->getPathname()), 'name="action"')) {
            $found[] = str_replace("$src/templates/", '', $file->getPathname());
        }
    }

    return $found === [] ?: implode(', ', $found);
});

check('the CP stylesheet uses Craft’s variables, not its own colours', function() use ($src) {
    $css = file_get_contents("$src/web/assets/cp/dist/csr-cp.css");
    preg_match_all('/#[0-9a-f]{3,8}\b(?![\w-]*\s*\{)|rgba?\(/i', preg_replace('/#csr-[\w-]+/', '', $css), $m);

    return $m[0] === [] ?: implode(', ', array_unique($m[0]));
});

check('the desk widget brings the stylesheet with it onto the dashboard', function() {
    $view = Craft::$app->getView();
    $view->assetBundles = [];
    (new DeskWidget())->getBodyHtml();

    return isset($view->assetBundles[CpAsset::class]) ?: 'not registered';
});

echo "\nOver HTTP\n";

$http = new Client(['base_uri' => 'http://localhost/', 'cookies' => new CookieJar(), 'http_errors' => false]);
$csrf = (string)(json_decode((string)$http->get('index.php?p=actions/users/session-info', ['headers' => ['Accept' => 'application/json']])->getBody(), true)['csrfTokenValue'] ?? '');
$http->post('index.php?p=actions/users/login', ['headers' => ['Accept' => 'application/json'], 'form_params' => ['loginName' => 'admin', 'password' => 'claudepassword', 'CRAFT_CSRF_TOKEN' => $csrf]]);

$pages = [];
foreach (['tickets' => 'csr/tickets', 'ticket' => "csr/tickets/{$ticket?->id}", 'reports' => 'csr/reports', 'desk report' => 'csr/reports/desk&days=90', 'statuses' => 'csr/settings/statuses', 'SLA policy' => 'csr/settings/slas/new'] as $name => $path) {
    $response = $http->get("index.php?p=admin/$path");
    $pages[$name] = ['status' => $response->getStatusCode(), 'html' => (string)$response->getBody()];
}

foreach ($pages as $name => $page) {
    check("$name renders, with CSR’s stylesheet", fn() => $page['status'] === 200 && str_contains($page['html'], 'csr-cp.css') ?: "status {$page['status']}" . ($page['status'] === 200 ? ', no stylesheet' : ''));
}

check('the tickets-a-day chart is SVG with attribute heights, not styled divs', function() use ($pages) {
    $html = $pages['desk report']['html'];
    if (!str_contains($html, 'csr-chart')) {
        return str_contains($html, 'No tickets in this window') ? true : 'no chart';
    }

    return preg_match('/<svg class="csr-chart"[^>]*>.*?<rect [^>]*height="\d+"/s', $html) && !preg_match('/<div title="[^"]*"\s+style=/', $html)
        ?: 'chart drawn with inline styles';
});

check('the SLA preview starts hidden by attribute, not by style', function() use ($pages) {
    return (bool)preg_match('/id="csr-sla-preview" hidden/', $pages['SLA policy']['html']) ?: 'not hidden by attribute';
});

echo "\n$passed passed, $failed failed\n";
exit($failed === 0 ? 0 : 1);
