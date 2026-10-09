<?php
/**
 * CSR inbound email — the parsers, the three providers' verification, and email becoming tickets
 * and replies.
 *
 *     docker exec -w /var/www/html ddev-plugin-testing-web php /var/www/craft-csr/tests/integration/inbound.php
 *
 * No mail provider is reachable from the harness, so every delivery is a signed fixture from
 * `_inbound-fixtures.php`, verified by the same provider classes a real one goes through. Settings
 * and the edition change in memory only. Nothing is mailed: the mailer is intercepted and every
 * message is kept for inspection instead of sent. Self-cleaning — every row, ticket, asset and
 * queued job the run makes is removed at the end.
 */

$root = '/var/www/html';
require $root . '/bootstrap.php';

/** @var craft\console\Application $app */
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';

require __DIR__ . '/_inbound-fixtures.php';

use craft\db\Query;
use craft\mail\Mailer;
use craft\mail\Message;
use justinholtweb\csr\elements\Ticket;
use justinholtweb\csr\enums\StatusKind;
use justinholtweb\csr\mail\Addresses;
use justinholtweb\csr\mail\InboundMessage;
use justinholtweb\csr\mail\InboundRequest;
use justinholtweb\csr\mail\LoopGuard;
use justinholtweb\csr\mail\MimeParser;
use justinholtweb\csr\mail\MultipartParser;
use justinholtweb\csr\mail\providers\Mailgun;
use justinholtweb\csr\mail\providers\Postmark;
use justinholtweb\csr\mail\providers\SendGrid;
use justinholtweb\csr\mail\ReplyParser;
use justinholtweb\csr\models\Settings;
use justinholtweb\csr\Plugin;
use justinholtweb\csr\records\Table;
use justinholtweb\csr\services\Inbound;
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

function section(string $title): void
{
    echo "\n$title\n";
}

$plugin = Plugin::getInstance();
$inbound = $plugin->inbound;
$fixtures = dirname(__DIR__) . '/fixtures/inbound';
$tempDir = Craft::$app->getPath()->getTempPath() . '/csr-inbound-test';
$senders = ['pat@example.org', 'maria@example.org', 'kay@example.net', 'mallory@example.com', 'flood@example.org', 'ops@example.org', 'sg@example.org', 'att@example.org'];

// Pro, inbound on, in memory only.
$plugin->edition = Plugin::EDITION_PRO;
$settings = $plugin->getSettings();
$settings->inboundEnabled = true;
$settings->inboundAddress = FIXTURE_DESK;
$settings->inboundCreateTickets = true;
$settings->inboundUnverifiedSender = Settings::UNVERIFIED_NEW_TICKET;
$settings->inboundRateLimit = 0;
$settings->inboundReplayWindow = 300;
$settings->postmarkUsername = FIXTURE_POSTMARK_USER;
$settings->postmarkPassword = FIXTURE_POSTMARK_PASSWORD;
$settings->mailgunSigningKey = FIXTURE_MAILGUN_KEY;
$settings->sendgridPublicKey = '';
$settings->sendgridUsername = '';
$settings->sendgridPassword = '';
$settings->inboundAttachments = true;
$settings->attachmentsEnabled = true;
$settings->attachmentVolumeUid = Craft::$app->getVolumes()->getVolumeByHandle('images')?->uid;
$settings->allowedFileExtensions = 'png,jpg,pdf,txt';
$settings->maxFilesPerReply = 3;
$settings->maxFileSizeMb = 2;
$settings->notifyRequesterOnNew = false;
$settings->notifyAgentsOnNew = false;
$settings->notifyRequesterOnReply = false;
$settings->notifyAgentOnReply = false;
$settings->queueNotifications = false;
$settings->rateLimit = 0;
$settings->pigeonBridge = false;
$settings->slaEnabled = false;

// Every message the mailer is asked to send, kept and not sent.
$sent = [];
Event::on(Mailer::class, Mailer::EVENT_BEFORE_SEND, static function(MailEvent $event) use (&$sent) {
    $sent[] = $event->message;
    $event->isValid = false;
});

$queueFloor = (int)(new Query())->from('{{%queue}}')->max('id');
$inboundFloor = (int)(new Query())->from([Table::INBOUND])->max('id');

// Sweep a crashed earlier run.
Craft::$app->getDb()->createCommand()->delete(Table::INBOUND, ['fromEmail' => $senders])->execute();
foreach (Ticket::find()->isSpam(null)->requesterEmail($senders)->limit(null)->all() as $stale) {
    Craft::$app->getElements()->deleteElement($stale, true);
}

/** Receive through the webhook path and process the queued row straight away. */
$deliver = static function(string $provider, InboundRequest $request, ?int $now = null) use ($inbound): array {
    $result = $inbound->receive($provider, $request, $now);

    if ($result['status'] === 200 && $result['result'] === Inbound::RESULT_ACCEPTED && $result['id'] !== null) {
        $inbound->process($result['id']);
    }

    $row = $result['id'] !== null ? (new Query())->from([Table::INBOUND])->where(['id' => $result['id']])->one() : null;

    return $result + ['row' => $row ?: null];
};

$ticketOf = static fn(?array $row): ?Ticket => $row !== null && $row['ticketId'] !== null
    ? Ticket::find()->id((int)$row['ticketId'])->isSpam(null)->one()
    : null;

try {
    // ------------------------------------------------------------------------------------------
    section('Addresses and tokens');

    check('address lists split on commas outside quotes and brackets', function() {
        $list = Addresses::parseList('"Holt, Justin" <Justin@Example.com>, ops@example.com (Ops), undisclosed-recipients:;');

        return array_column($list, 'email') === ['justin@example.com', 'ops@example.com']
            && $list[0]['name'] === 'Holt, Justin'
            ?: json_encode($list);
    });

    check('a reply address is the desk address with a tag, one tag only', function() {
        return Addresses::replyAddress('Support+old@Desk.Example.com', str_repeat('a', 32)) === 'support+' . str_repeat('a', 32) . '@desk.example.com'
            ?: 'got ' . Addresses::replyAddress('Support+old@Desk.Example.com', str_repeat('a', 32));
    });

    check('the token is read only from the desk’s own mailbox, lower-cased', function() {
        $token = 'k3v9' . str_repeat('x', 28);

        return Addresses::tokenFrom('SUPPORT+' . strtoupper($token) . '@desk.example.com', FIXTURE_DESK) === $token
            && Addresses::tokenFrom('support+' . $token . '@other.example.com', FIXTURE_DESK) === null
            && Addresses::tokenFrom('sales+' . $token . '@desk.example.com', FIXTURE_DESK) === null
            ?: 'mismatch';
    });

    check('a token-shaped tag with pattern syntax in it is not a token', function() {
        return Addresses::tokenFrom('support+' . str_repeat('a', 30) . '%_@desk.example.com', FIXTURE_DESK) === null
            && Addresses::tokenFrom('support+*@desk.example.com', FIXTURE_DESK) === null
            && !Addresses::isToken(str_repeat('a', 31))
            ?: 'accepted';
    });

    check('Message-IDs come out of In-Reply-To and References without brackets', function() {
        return Addresses::messageIds(' <a@x> <b@y>  <a@x>') === ['a@x', 'b@y'] ?: json_encode(Addresses::messageIds(' <a@x> <b@y>  <a@x>'));
    });

    // ------------------------------------------------------------------------------------------
    section('Quoted replies, signatures and HTML');

    check('Gmail’s wrapped “On … wrote:” and the quote under it are cut', function() {
        $text = "Still broken.\n\nOn Thu, 8 Oct 2026 at 17:02, Support <support+abc@desk.example.com>\nwrote:\n> We fixed it.\n>\n> The desk";

        return ReplyParser::strip($text) === 'Still broken.' ?: json_encode(ReplyParser::strip($text));
    });

    check('Outlook’s From/Sent/To header block is cut, a stray “From:” line is not', function() {
        $outlook = "Here you go.\n\n________________________________\nFrom: Support <support@desk.example.com>\nSent: Thursday, October 8, 2026 5:02 PM\nTo: Pat\nSubject: Re: Help";
        $stray = "From: my phone, so excuse typos.\nIt still fails.";

        return ReplyParser::strip($outlook) === 'Here you go.' && ReplyParser::strip($stray) === $stray
            ?: json_encode([ReplyParser::strip($outlook), ReplyParser::strip($stray)]);
    });

    check('signatures go: “-- ” and “Sent from my iPhone”', function() {
        return ReplyParser::strip("Thanks!\n\n-- \nPat Customer\nAcme Ltd") === 'Thanks!'
            && ReplyParser::strip("Done.\n\nSent from my iPhone") === 'Done.'
            ?: 'kept';
    });

    check('inline answers between quotes are kept; only the trailing quote goes', function() {
        $text = "> Which browser?\nFirefox 140.\n> Which version of the app?\nThe latest.\n\n> old stuff\n> more";

        return ReplyParser::strip($text) === "> Which browser?\nFirefox 140.\n> Which version of the app?\nThe latest." ?: json_encode(ReplyParser::strip($text));
    });

    check('HTML becomes text: blocks to lines, links keep their address, script/style/quotes dropped', function() {
        $html = '<html><head><style>p{}</style><script>alert(1)</script></head><body><p>Hi &amp; hello</p>'
            . '<p>See <a href="https://x.example/y">this page</a></p><ul><li>One</li><li>Two</li></ul>'
            . '<div class="gmail_quote">On … wrote:<blockquote>old</blockquote></div><img src=x onerror=alert(2)></body></html>';
        $text = ReplyParser::htmlToText($html);

        return $text === "Hi & hello\n\nSee this page (https://x.example/y)\n\n- One\n- Two"
            ?: json_encode($text);
    });

    check('UTF-8 survives the HTML path', function() {
        return ReplyParser::htmlToText('<p>Café — €40</p>') === 'Café — €40' ?: ReplyParser::htmlToText('<p>Café — €40</p>');
    });

    // ------------------------------------------------------------------------------------------
    section('MIME');

    $store = static function(string $bytes) use ($tempDir): string {
        return (new InboundRequest(tempDir: $tempDir))->writeTemp($bytes);
    };

    $gmail = str_replace('{{TOKEN}}', str_repeat('q', 32), (string)file_get_contents("$fixtures/gmail-reply.eml"));
    $parsed = MimeParser::parse($gmail, $store);

    check('encoded-word From and Subject are decoded', function() use ($parsed) {
        return $parsed->fromEmail === 'maria@example.org' && $parsed->fromName === 'María López'
            && $parsed->subject === 'Re: Ticket #1000: “Invoice wrong”'
            ?: json_encode([$parsed->fromEmail, $parsed->fromName, $parsed->subject]);
    });

    check('quoted-printable text across a soft line break, and the base64 HTML part', function() use ($parsed) {
        return str_contains($parsed->text, 'The total should be €40, not €400.')
            && str_contains($parsed->text, '<support+' . str_repeat('q', 32) . '@desk.example.com>')
            && str_contains($parsed->html, 'gmail_quote')
            ?: json_encode([$parsed->text, $parsed->html]);
    });

    check('threading headers: Message-ID, In-Reply-To, References', function() use ($parsed) {
        return $parsed->messageId === 'CAF+gmail-reply-001@mail.gmail.com'
            && $parsed->inReplyTo === ['csr.outbound-001@desk.example.com']
            && $parsed->threadIds()[0] === 'csr.outbound-001@desk.example.com'
            ?: json_encode([$parsed->messageId, $parsed->inReplyTo, $parsed->references]);
    });

    check('an RFC 2231 file name and a base64 attachment, byte for byte', function() use ($parsed) {
        $a = $parsed->attachments[0] ?? null;

        return $a !== null && $a->filename === 'résumé screen.png' && $a->contentType === 'image/png'
            && file_get_contents($a->path) === tinyPng()
            ?: json_encode($a?->toArray());
    });

    check('the reply text, stripped, is just what Maria wrote', function() use ($parsed) {
        $text = ReplyParser::newText($parsed->text, $parsed->html);

        return $text === "Thanks — the invoice is still wrong after the fix.\nThe total should be €40, not €400." ?: json_encode($text);
    });

    check('an ISO-8859-1 body is converted to UTF-8', function() use ($fixtures, $store) {
        $m = MimeParser::parse((string)file_get_contents("$fixtures/out-of-office.eml"), $store);

        return str_contains($m->text, 'Café closed.') ?: json_encode($m->text);
    });

    check('multipart/form-data parsing is binary-safe', function() use ($store) {
        $binary = tinyPng() . "\r\n--not-a-boundary\r\n\x00\xff";
        [$body, $type] = sendgridBody(['from' => 'a@b.c', 'text' => "two\r\nlines"], ['attachment1' => ['filename' => 'x.png', 'type' => 'image/png', 'bytes' => $binary]]);
        [$fields, $files] = MultipartParser::parse($body, $type, $store);

        return $fields['text'] === "two\r\nlines" && file_get_contents($files['attachment1']['tmp_name']) === $binary
            ?: json_encode([$fields, $files]);
    });

    // ------------------------------------------------------------------------------------------
    section('Loop protection');

    $loop = static function(array $headers, string $from = 'person@example.org', string $subject = 'Hi'): ?string {
        $m = new InboundMessage();
        $m->fromEmail = $from;
        $m->subject = $subject;

        foreach ($headers as $name => $value) {
            $m->addHeader($name, $value);
        }

        return LoopGuard::reason($m, [FIXTURE_DESK, 'noreply@desk.example.com']);
    };

    check('Auto-Submitted other than “no” is automated; “no” is a person', function() use ($loop) {
        return $loop(['Auto-Submitted' => 'auto-replied']) === LoopGuard::AUTO_SUBMITTED
            && $loop(['Auto-Submitted' => 'no']) === null
            ?: 'wrong';
    });

    check('Precedence bulk/list/junk, X-Autoreply, List-Id and bounces are automated', function() use ($loop) {
        return $loop(['Precedence' => 'bulk']) === LoopGuard::PRECEDENCE
            && $loop(['Precedence' => 'list']) === LoopGuard::PRECEDENCE
            && $loop(['X-Autoreply' => 'yes']) === LoopGuard::AUTO_REPLY
            && $loop(['List-Id' => '<team.example.org>']) === LoopGuard::MAILING_LIST
            && $loop([], 'MAILER-DAEMON@mx.example.org') === LoopGuard::BOUNCE
            ?: 'wrong';
    });

    check('mail from the desk’s own address, or a tagged variant of it, is a loop', function() use ($loop) {
        return $loop([], 'support+' . str_repeat('a', 32) . '@desk.example.com') === LoopGuard::OWN_ADDRESS
            && $loop([], 'NoReply@desk.example.com') === LoopGuard::OWN_ADDRESS
            ?: 'not caught';
    });

    // ------------------------------------------------------------------------------------------
    section('Postmark: basic auth');

    $postmark = new Postmark(FIXTURE_POSTMARK_USER, FIXTURE_POSTMARK_PASSWORD);

    check('the right credentials pass', fn() => $postmark->verify(postmarkRequest(postmarkPayload()), time()) === null ?: 'refused');
    check('a wrong password is refused', fn() => $postmark->verify(postmarkRequest(postmarkPayload(), FIXTURE_POSTMARK_USER, 'nope'), time()) === 'bad credentials' ?: 'accepted');
    check('a wrong username is refused', fn() => $postmark->verify(postmarkRequest(postmarkPayload(), 'admin', FIXTURE_POSTMARK_PASSWORD), time()) === 'bad credentials' ?: 'accepted');
    check('no credentials at all are refused', fn() => $postmark->verify(postmarkRequest(postmarkPayload(), null, null), time()) !== null ?: 'accepted');
    check('unconfigured refuses everything, even empty credentials', function() {
        $empty = new Postmark('', '');

        return !$empty->isConfigured() && $empty->verify(postmarkRequest(postmarkPayload(), '', ''), time()) === 'not configured' ?: 'accepted';
    });

    // ------------------------------------------------------------------------------------------
    section('Mailgun: HMAC, timestamp, single-use token');

    $seen = [];
    $mailgun = new Mailgun(FIXTURE_MAILGUN_KEY, 300, static function(string $token) use (&$seen): bool {
        if (isset($seen[$token])) {
            return false;
        }

        return $seen[$token] = true;
    });

    check('a correctly signed post passes', fn() => $mailgun->verify(mailgunRequest(), time()) === null ?: 'refused');
    check('signed with the wrong key is refused', fn() => $mailgun->verify(mailgunRequest(key: 'other-key'), time()) === 'bad signature' ?: 'accepted');
    check('a tampered timestamp breaks the signature', function() use ($mailgun) {
        $request = mailgunRequest();
        $request->post['timestamp'] = (string)((int)$request->post['timestamp'] + 1);

        return $mailgun->verify($request, time()) === 'bad signature' ?: 'accepted';
    });
    check('a correctly signed but stale post is refused (replay window)', fn() => $mailgun->verify(mailgunRequest(timestamp: time() - 3600), time()) === 'stale timestamp' ?: 'accepted');
    check('the same token twice is refused the second time', function() use ($mailgun) {
        $token = bin2hex(random_bytes(25));
        $first = $mailgun->verify(mailgunRequest(token: $token), time());
        $second = $mailgun->verify(mailgunRequest(token: $token), time());

        return $first === null && $second === 'replayed token' ?: json_encode([$first, $second]);
    });
    check('a forged signature does not burn a real token', function() use ($mailgun) {
        $token = bin2hex(random_bytes(25));
        $forged = $mailgun->verify(mailgunRequest(token: $token, key: 'wrong'), time());
        $real = $mailgun->verify(mailgunRequest(token: $token), time());

        return $forged === 'bad signature' && $real === null ?: json_encode([$forged, $real]);
    });
    check('unsigned is refused', function() use ($mailgun) {
        $request = mailgunRequest();
        unset($request->post['signature']);

        return $mailgun->verify($request, time()) === 'unsigned' ?: 'accepted';
    });

    // ------------------------------------------------------------------------------------------
    section('SendGrid: ECDSA over timestamp + raw body');

    [$sgPrivate, $sgPublic] = sendgridKeys();
    $sendgrid = new SendGrid(sendgridBareKey($sgPublic), '', '', 300);
    [$sgBody, $sgType] = sendgridBody([
        'from' => 'SG Person <sg@example.org>',
        'to' => FIXTURE_DESK,
        'subject' => 'From SendGrid',
        'text' => 'Parsed by SendGrid.',
        'headers' => "Message-ID: <sg-" . bin2hex(random_bytes(6)) . "@example.org>\nFrom: SG Person <sg@example.org>",
        'envelope' => json_encode(['to' => [FIXTURE_DESK], 'from' => 'sg@example.org']),
        'attachments' => '0',
    ]);

    check('a signed raw body passes, with the key pasted as bare base64', fn() => $sendgrid->verify(sendgridRequest($sgBody, $sgType, $sgPrivate), time()) === null ?: 'refused');
    check('…and with the key pasted as PEM', fn() => (new SendGrid($sgPublic, '', '', 300))->verify(sendgridRequest($sgBody, $sgType, $sgPrivate), time()) === null ?: 'refused');
    check('one changed byte in the body is refused', function() use ($sendgrid, $sgBody, $sgType, $sgPrivate) {
        $request = sendgridRequest($sgBody, $sgType, $sgPrivate);
        $request->rawBody = str_replace('Parsed by', 'Parsed bY', $request->rawBody);

        return $sendgrid->verify($request, time()) === 'bad signature' ?: 'accepted';
    });
    check('a different key’s signature is refused', function() use ($sendgrid, $sgBody, $sgType) {
        [$otherPrivate] = sendgridKeys();

        return $sendgrid->verify(sendgridRequest($sgBody, $sgType, $otherPrivate), time()) === 'bad signature' ?: 'accepted';
    });
    check('a correctly signed but stale post is refused', fn() => $sendgrid->verify(sendgridRequest($sgBody, $sgType, $sgPrivate, time() - 3600), time()) === 'stale timestamp' ?: 'accepted');
    check('no raw body (PHP parsed the multipart) is refused, saying why', function() use ($sendgrid, $sgBody, $sgType, $sgPrivate) {
        $request = sendgridRequest($sgBody, $sgType, $sgPrivate);
        $request->rawBody = '';
        $request->post = ['from' => 'x@y.z'];

        return str_starts_with((string)$sendgrid->verify($request, time()), 'raw body unavailable') ?: 'accepted';
    });
    check('basic-auth fallback when no key is set', function() use ($sgBody, $sgType) {
        $basic = new SendGrid('', 'sg-user', 'sg-pass', 300);
        $ok = new InboundRequest(['content-type' => $sgType], $sgBody, [], [], 'sg-user', 'sg-pass');
        $bad = new InboundRequest(['content-type' => $sgType], $sgBody, [], [], 'sg-user', 'nope');

        return $basic->verify($ok, time()) === null && $basic->verify($bad, time()) === 'bad credentials' ?: 'wrong';
    });
    check('the verified raw body is parsed by hand: fields and envelope recipients', function() use ($sendgrid, $sgBody, $sgType, $sgPrivate, $tempDir) {
        $request = sendgridRequest($sgBody, $sgType, $sgPrivate, null, $tempDir);
        $m = $sendgrid->parse($request);

        return $m->fromEmail === 'sg@example.org' && $m->subject === 'From SendGrid' && $m->text === 'Parsed by SendGrid.'
            && $m->envelopeTo === [FIXTURE_DESK] && $m->messageId !== null
            ?: json_encode($m->toArray());
    });

    // ------------------------------------------------------------------------------------------
    section('The webhook path: refusals');

    check('an unknown provider is a 404', fn() => $inbound->receive('sparkpost', postmarkRequest(postmarkPayload()))['status'] === 404 ?: 'not 404');

    check('a provider with no secret configured is a 403, and nothing is stored', function() use ($inbound, $settings) {
        $settings->postmarkPassword = '';
        $before = (int)(new Query())->from([Table::INBOUND])->count();

        try {
            $result = $inbound->receive('postmark', postmarkRequest(postmarkPayload(), FIXTURE_POSTMARK_USER, ''));
        } finally {
            $settings->postmarkPassword = FIXTURE_POSTMARK_PASSWORD;
        }

        return $result['status'] === 403 && (int)(new Query())->from([Table::INBOUND])->count() === $before ?: json_encode($result);
    });

    check('bad credentials are a 401, and nothing is stored', function() use ($inbound) {
        $before = (int)(new Query())->from([Table::INBOUND])->count();
        $result = $inbound->receive('postmark', postmarkRequest(postmarkPayload(), FIXTURE_POSTMARK_USER, 'wrong'));

        return $result['status'] === 401 && (int)(new Query())->from([Table::INBOUND])->count() === $before ?: json_encode($result);
    });

    check('accepted mail is queued, not processed in the request', function() use ($inbound, $queueFloor) {
        $result = $inbound->receive('postmark', postmarkRequest(postmarkPayload(['From' => 'pat@example.org', 'Subject' => 'Queued one'])));
        $row = (new Query())->from([Table::INBOUND])->where(['id' => $result['id']])->one();
        $job = (new Query())->from('{{%queue}}')->where(['>', 'id', $queueFloor])->andWhere(['like', 'job', 'ProcessInboundEmail'])->exists();

        // Processed now so the rest of the run starts clean.
        $inbound->process((int)$result['id']);

        return $result['status'] === 200 && $row['status'] === Inbound::STATUS_QUEUED && $job ?: json_encode([$result, $row['status'] ?? null, $job]);
    });

    // ------------------------------------------------------------------------------------------
    section('New email raises a ticket');

    $newId = 'new-' . bin2hex(random_bytes(6)) . '@example.org';
    $first = $deliver('postmark', postmarkRequest(postmarkPayload([
        '_messageId' => $newId,
        'Subject' => 'Order 5521 never arrived',
        'TextBody' => "Hello,\n\nMy order 5521 never arrived.\n\nPat",
    ])));
    $ticket = $ticketOf($first['row']);

    check('a ticket from the email: source email, guest requester, subject and text', function() use ($first, $ticket) {
        return $first['row']['status'] === Inbound::STATUS_TICKET && $ticket !== null
            && $ticket->source === 'email' && $ticket->requesterEmail === 'pat@example.org'
            && $ticket->requesterId === null && $ticket->title === 'Order 5521 never arrived'
            && str_contains((string)$ticket->message, 'My order 5521 never arrived.')
            ?: json_encode([$first['row'], $ticket?->toArray(['source', 'requesterEmail', 'title'])]);
    });

    check('the ticket has a 32-character lower-case reply token, separate from its reference', function() use ($ticket) {
        return $ticket !== null && Addresses::isToken((string)$ticket->replyToken) && $ticket->replyToken !== $ticket->reference ?: 'no token';
    });

    check('the stored payload is emptied once the email has been handled', fn() => $first['row']['payload'] === null ?: 'payload kept');

    check('the same Message-ID delivered again is a duplicate, not a second ticket', function() use ($deliver, $newId) {
        $again = $deliver('postmark', postmarkRequest(postmarkPayload(['_messageId' => $newId, 'Subject' => 'Order 5521 never arrived'])));
        $count = (int)Ticket::find()->requesterEmail('pat@example.org')->title('Order 5521 never arrived')->isSpam(null)->count();

        return $again['result'] === Inbound::RESULT_DUPLICATE && $count === 1 ?: json_encode([$again['result'], $count]);
    });

    // ------------------------------------------------------------------------------------------
    section('Email going out carries the reply address');

    check('a customer email gets Reply-To support+<token>@, a remembered Message-ID, and threads under theirs', function() use ($inbound, $ticket, $newId) {
        $message = new Message();
        $message->setTo('pat@example.org')->setSubject('x')->setTextBody('y');
        $inbound->prepareOutgoing($message, $ticket);
        $headers = $message->getSymfonyEmail()->getHeaders();
        $replyTo = array_keys((array)$message->getReplyTo())[0] ?? '';
        $messageId = $headers->get('Message-ID')?->getBodyAsString() ?? '';
        $stored = (new Query())->from([Table::EMAIL_THREADS])->where(['ticketId' => $ticket->id, 'direction' => 'out'])->exists();

        return $replyTo === 'support+' . $ticket->replyToken . '@desk.example.com'
            && str_starts_with($messageId, '<csr.') && $stored
            && str_contains((string)$headers->get('In-Reply-To')?->getBodyAsString(), $newId)
            && !$headers->has('Auto-Submitted')
            ?: json_encode([$replyTo, $messageId, $stored, $headers->get('In-Reply-To')?->getBodyAsString()]);
    });

    check('the automatic acknowledgement says so (Auto-Submitted: auto-replied)', function() use ($plugin, $ticket, $settings, &$sent) {
        $settings->notifyRequesterOnNew = true;
        $sent = [];

        try {
            $plugin->notifications->deliver($ticket, 'raised');
        } finally {
            $settings->notifyRequesterOnNew = false;
        }

        $message = $sent[0] ?? null;

        return $message instanceof Message
            && $message->getSymfonyEmail()->getHeaders()->get('Auto-Submitted')?->getBodyAsString() === 'auto-replied'
            && str_contains(implode(',', array_keys((array)$message->getReplyTo())), '+' . $ticket->replyToken . '@')
            ?: 'headers missing (' . count($sent) . ' sent)';
    });

    check('with inbound email off, nothing is added to outgoing mail', function() use ($inbound, $ticket, $settings) {
        $settings->inboundEnabled = false;

        try {
            $message = new Message();
            $message->setTo('pat@example.org');
            $inbound->prepareOutgoing($message, $ticket);
        } finally {
            $settings->inboundEnabled = true;
        }

        return in_array($message->getReplyTo(), [null, [], ''], true) ?: json_encode($message->getReplyTo());
    });

    // ------------------------------------------------------------------------------------------
    section('Replies land on their ticket');

    $pending = $plugin->statuses->getFirstOfKind(StatusKind::Pending);
    $plugin->tickets->setStatus($ticket, (int)$pending->id, true, false);

    $reply = $deliver('mailgun', mailgunRequest([
        'recipient' => 'support+' . $ticket->replyToken . '@desk.example.com',
        'To' => 'support+' . $ticket->replyToken . '@desk.example.com',
        'subject' => 'Re: Order 5521 never arrived',
        'body-plain' => "It turned up this morning, thanks!\n\nOn Fri, 9 Oct 2026, Support <support@desk.example.com> wrote:\n> Sorry to hear that.",
    ]));
    $ticket = Ticket::find()->id($ticket->id)->isSpam(null)->one();
    $replies = $ticket->getReplies(false);
    $last = end($replies) ?: null;

    check('a reply to the tagged address is appended, stripped, as the customer’s', function() use ($reply, $last, $ticket) {
        return $reply['row']['status'] === Inbound::STATUS_REPLY && (int)$reply['row']['ticketId'] === (int)$ticket->id
            && $last !== null && !$last->byAgent && $last->source === 'email'
            && str_contains($last->body, 'It turned up this morning') && !str_contains($last->body, 'Sorry to hear')
            ?: json_encode([$reply['row'], $last?->body]);
    });

    check('the reply took the ticket out of pending (the SLA clock resumes)', function() use ($ticket) {
        return $ticket->getStatusKind() === StatusKind::Open && $ticket->clockPausedAt === null ?: $ticket->getStatusKind()->value;
    });

    check('the reply text is escaped, not rendered', function() use ($deliver, $ticket) {
        $result = $deliver('mailgun', mailgunRequest([
            'recipient' => 'support+' . $ticket->replyToken . '@desk.example.com',
            'body-plain' => '<script>alert(1)</script><img src=x onerror=alert(2)> plain',
        ]));
        $reply = Craft::$app->getDb()->createCommand('SELECT body FROM ' . Craft::$app->getDb()->getSchema()->getRawTableName(Table::REPLIES) . ' WHERE id = :id', [':id' => $result['row']['replyId']])->queryScalar();

        return is_string($reply) && !str_contains($reply, '<script') && !str_contains($reply, '<img') && str_contains($reply, '&lt;script&gt;')
            ?: (string)$reply;
    });

    check('a reply carrying only In-Reply-To (tag lost) still finds the ticket by header', function() use ($deliver, $ticket, $sgPrivate, $tempDir, $settings, $sgPublic) {
        $settings->sendgridPublicKey = sendgridBareKey($sgPublic);
        $outId = (new Query())->select(['messageId'])->from([Table::EMAIL_THREADS])->where(['ticketId' => $ticket->id, 'direction' => 'out'])->scalar();

        [$body, $type] = sendgridBody([
            'from' => 'Pat <pat@example.org>',
            'to' => FIXTURE_DESK,
            'subject' => 'Re: your ticket',
            'text' => "One more thing: the box was damaged.\n\n> quoted",
            'headers' => "Message-ID: <sg-" . bin2hex(random_bytes(6)) . "@example.org>\nIn-Reply-To: <$outId>\nFrom: Pat <pat@example.org>",
            'attachments' => '0',
        ]);

        $result = $deliver('sendgrid', sendgridRequest($body, $type, $sgPrivate, null, $tempDir));

        return $result['row']['status'] === Inbound::STATUS_REPLY && (int)$result['row']['ticketId'] === (int)$ticket->id
            && str_contains((string)$result['row']['reason'], 'headers')
            ?: json_encode($result['row']);
    });

    check('a well-formed token that belongs to no ticket raises a new ticket instead', function() use ($deliver) {
        $result = $deliver('mailgun', mailgunRequest([
            'recipient' => 'support+' . str_repeat('z', 32) . '@desk.example.com',
            'subject' => 'Guessing tokens',
            'body-plain' => 'hello',
        ]));

        return $result['row']['status'] === Inbound::STATUS_TICKET ?: json_encode($result['row']);
    });

    // ------------------------------------------------------------------------------------------
    section('Sender verification');

    check('a reply to Pat’s ticket from somebody else becomes their own ticket', function() use ($deliver, $ticket) {
        $before = count(Ticket::find()->id($ticket->id)->isSpam(null)->one()->getReplies());
        $result = $deliver('mailgun', mailgunRequest([
            'recipient' => 'support+' . $ticket->replyToken . '@desk.example.com',
            'from' => 'Mallory <mallory@example.com>',
            'sender' => 'mallory@example.com',
            'subject' => 'Re: Order 5521',
            'body-plain' => 'Please send the refund to my account instead.',
        ]));
        $fresh = Ticket::find()->id($ticket->id)->isSpam(null)->one();
        $new = $result['row']['ticketId'] !== null ? Ticket::find()->id((int)$result['row']['ticketId'])->isSpam(null)->one() : null;

        return $result['row']['status'] === Inbound::STATUS_TICKET && $new?->requesterEmail === 'mallory@example.com'
            && (int)$new->id !== (int)$ticket->id && count($fresh->getReplies()) === $before
            ?: json_encode($result['row']);
    });

    check('…or is refused, when the setting says so', function() use ($deliver, $ticket, $settings) {
        $settings->inboundUnverifiedSender = Settings::UNVERIFIED_REJECT;

        try {
            $result = $deliver('mailgun', mailgunRequest([
                'recipient' => 'support+' . $ticket->replyToken . '@desk.example.com',
                'from' => 'mallory@example.com',
                'subject' => 'Re: again',
                'body-plain' => 'Me again.',
            ]));
        } finally {
            $settings->inboundUnverifiedSender = Settings::UNVERIFIED_NEW_TICKET;
        }

        return $result['row']['status'] === Inbound::STATUS_REJECTED && $result['row']['ticketId'] === null ?: json_encode($result['row']);
    });

    check('the sender match is case-insensitive', function() use ($deliver, $ticket) {
        $result = $deliver('mailgun', mailgunRequest([
            'recipient' => 'support+' . $ticket->replyToken . '@desk.example.com',
            'from' => 'PAT@EXAMPLE.ORG',
            'body-plain' => 'Shouting my address.',
        ]));

        return $result['row']['status'] === Inbound::STATUS_REPLY ?: json_encode($result['row']);
    });

    // ------------------------------------------------------------------------------------------
    section('Loops, automation and rate limits');

    check('an out-of-office reply is ignored, not a ticket', function() use ($inbound, $fixtures) {
        $result = $inbound->importRaw((string)file_get_contents("$fixtures/out-of-office.eml"), 'import', false);
        $row = (new Query())->from([Table::INBOUND])->where(['id' => $result['id']])->one();

        return $row['status'] === Inbound::STATUS_IGNORED && str_contains((string)$row['reason'], LoopGuard::AUTO_SUBMITTED) && $row['ticketId'] === null
            ?: json_encode($row);
    });

    check('Precedence: bulk is ignored', function() use ($deliver) {
        $result = $deliver('mailgun', mailgunRequest(['from' => 'ops@example.org', 'Precedence' => 'bulk', 'body-plain' => 'newsletter']));

        return $result['row']['status'] === Inbound::STATUS_IGNORED ?: json_encode($result['row']);
    });

    check('mail from the desk’s own address is ignored', function() use ($deliver) {
        $result = $deliver('postmark', postmarkRequest(postmarkPayload([
            'From' => FIXTURE_DESK,
            'FromFull' => ['Email' => FIXTURE_DESK, 'Name' => 'Support'],
        ])));

        return $result['row']['status'] === Inbound::STATUS_IGNORED ?: json_encode($result['row']);
    });

    check('past the hourly limit, a sender’s mail is dropped and logged', function() use ($deliver, $settings) {
        $settings->inboundRateLimit = 2;

        try {
            $results = [];

            for ($i = 0; $i < 3; $i++) {
                $results[] = $deliver('postmark', postmarkRequest(postmarkPayload([
                    'From' => 'flood@example.org',
                    'FromFull' => ['Email' => 'flood@example.org', 'Name' => 'Flood'],
                    'Subject' => "Flood $i",
                ])))['result'];
            }
        } finally {
            $settings->inboundRateLimit = 0;
        }

        return $results === [Inbound::RESULT_ACCEPTED, Inbound::RESULT_ACCEPTED, Inbound::RESULT_RATE_LIMITED] ?: json_encode($results);
    });

    check('with new tickets by email off, a non-reply is refused', function() use ($deliver, $settings) {
        $settings->inboundCreateTickets = false;

        try {
            $result = $deliver('postmark', postmarkRequest(postmarkPayload(['Subject' => 'Not a reply'])));
        } finally {
            $settings->inboundCreateTickets = true;
        }

        return $result['row']['status'] === Inbound::STATUS_REJECTED ?: json_encode($result['row']);
    });

    check('a cancelled EVENT_BEFORE_PROCESS drops the email', function() use ($deliver) {
        $handler = static function(justinholtweb\csr\events\InboundEmailEvent $event) {
            $event->isValid = false;
        };
        Event::on(Inbound::class, Inbound::EVENT_BEFORE_PROCESS, $handler);

        try {
            $result = $deliver('postmark', postmarkRequest(postmarkPayload(['Subject' => 'Vetoed'])));
        } finally {
            Event::off(Inbound::class, Inbound::EVENT_BEFORE_PROCESS, $handler);
        }

        return $result['row']['status'] === Inbound::STATUS_IGNORED ?: json_encode($result['row']);
    });

    // ------------------------------------------------------------------------------------------
    section('Attachments');

    check('content sniffing: a real PNG is a PNG; text named .png is not', function() use ($tempDir) {
        $request = new InboundRequest(tempDir: $tempDir);
        $png = $request->writeTemp(tinyPng());
        $fake = $request->writeTemp("MZ\x90\x00 this is not an image");

        return Inbound::contentMatchesExtension($png, 'png') && !Inbound::contentMatchesExtension($fake, 'png') ?: 'wrong';
    });

    $attachmentMessageId = 'att-' . bin2hex(random_bytes(6)) . '@example.org';
    $withFiles = $deliver('postmark', postmarkRequest(postmarkPayload([
        '_messageId' => $attachmentMessageId,
        'From' => 'att@example.org',
        'FromFull' => ['Email' => 'att@example.org', 'Name' => 'Att'],
        'Subject' => 'Screenshots attached',
        'Attachments' => [
            ['Name' => 'screen.png', 'Content' => base64_encode(tinyPng()), 'ContentType' => 'image/png', 'ContentLength' => strlen(tinyPng())],
            ['Name' => 'setup.exe', 'Content' => base64_encode("MZ\x90\x00"), 'ContentType' => 'application/octet-stream', 'ContentLength' => 4],
            ['Name' => 'invoice.png', 'Content' => base64_encode('<?php echo 1; ?>'), 'ContentType' => 'image/png', 'ContentLength' => 16],
        ],
    ]), tempDir: $tempDir));
    $attTicket = $ticketOf($withFiles['row']);

    check('the allowed file is stored as an asset on the ticket, through CSR’s download only', function() use ($attTicket) {
        $files = $attTicket?->getAttachments() ?? [];

        return count($files) === 1 && $files[0]->filename === 'screen.png' && $files[0]->getExists()
            && str_contains((string)$files[0]->getDownloadUrl(), 'csr/tickets/download')
            ?: json_encode(array_map(static fn($a) => $a->filename, $files));
    });

    check('the refused files are named in an internal note, with the reason', function() use ($attTicket) {
        $notes = array_values(array_filter($attTicket?->getReplies() ?? [], static fn($r) => $r->isNote));
        $body = $notes[0]->body ?? '';

        return count($notes) === 1 && str_contains($body, 'setup.exe') && str_contains($body, 'file type not allowed')
            && str_contains($body, 'invoice.png') && str_contains($body, 'content does not match')
            ?: $body;
    });

    check('nothing is left in the inbound storage directory afterwards', function() use ($inbound) {
        $left = is_dir($inbound->storageDir()) ? array_diff(scandir($inbound->storageDir()) ?: [], ['.', '..']) : [];

        return $left === [] ?: implode(', ', $left);
    });

    check('with attachments off, files are dropped (and noted) but the ticket is still raised', function() use ($deliver, $settings) {
        $settings->attachmentsEnabled = false;

        try {
            $result = $deliver('postmark', postmarkRequest(postmarkPayload([
                'From' => 'att@example.org',
                'FromFull' => ['Email' => 'att@example.org', 'Name' => 'Att'],
                'Subject' => 'No attachments please',
                'Attachments' => [['Name' => 'a.png', 'Content' => base64_encode(tinyPng()), 'ContentType' => 'image/png']],
            ])));
        } finally {
            $settings->attachmentsEnabled = true;
        }

        $ticket = Ticket::find()->id((int)$result['row']['ticketId'])->isSpam(null)->one();

        return $result['row']['status'] === Inbound::STATUS_TICKET && $ticket->getAttachments() === []
            ?: json_encode($result['row']);
    });

    // ------------------------------------------------------------------------------------------
    section('Merged tickets, IMAP and the edition');

    check('a reply to a merged ticket lands on the ticket it was merged into', function() use ($deliver, $plugin, $ticket, $attTicket) {
        $plugin->tickets->merge($attTicket, $ticket);
        $result = $deliver('mailgun', mailgunRequest([
            'recipient' => 'support+' . $attTicket->replyToken . '@desk.example.com',
            'from' => 'att@example.org',
            'body-plain' => 'Following up.',
        ]));

        // Att is not Pat: following the merge does not bypass the sender check.
        $asOther = $result['row']['status'] === Inbound::STATUS_TICKET;

        $resultPat = $deliver('mailgun', mailgunRequest([
            'recipient' => 'support+' . $attTicket->replyToken . '@desk.example.com',
            'from' => 'pat@example.org',
            'body-plain' => 'Pat following up on the merged one.',
        ]));

        return $asOther && $resultPat['row']['status'] === Inbound::STATUS_REPLY && (int)$resultPat['row']['ticketId'] === (int)$ticket->id
            ?: json_encode([$result['row']['status'], $resultPat['row']]);
    });

    check('without the imap extension, the poll explains instead of failing obscurely', function() use ($inbound) {
        if (Inbound::imapAvailable()) {
            return true;
        }

        try {
            $inbound->poll();
        } catch (RuntimeException $e) {
            return str_contains($e->getMessage(), 'imap extension') ?: $e->getMessage();
        }

        return 'no exception';
    });

    check('a raw .eml imported as a new email: HTML-only body becomes text', function() use ($inbound, $fixtures) {
        $result = $inbound->importRaw((string)file_get_contents("$fixtures/new-html.eml"), 'import', false);
        $row = (new Query())->from([Table::INBOUND])->where(['id' => $result['id']])->one();
        $ticket = Ticket::find()->id((int)$row['ticketId'])->isSpam(null)->one();

        return $row['status'] === Inbound::STATUS_TICKET && $ticket->requesterName === 'Dee, Kay'
            && str_contains((string)$ticket->message, 'reset link (https://example.net/reset) fails')
            && !str_contains((string)$ticket->message, 'alert(1)')
            ?: json_encode([$row, $ticket?->message]);
    });

    check('on Lite, inbound email is off whatever the setting says', function() use ($plugin, $inbound) {
        $plugin->edition = Plugin::EDITION_LITE;

        try {
            return !$inbound->isEnabled() ?: 'enabled on Lite';
        } finally {
            $plugin->edition = Plugin::EDITION_PRO;
        }
    });
} finally {
    // ------------------------------------------------------------------------------------------
    section('Cleaning up');

    $ticketIds = (new Query())->select(['ticketId'])->from([Table::INBOUND])->where(['>', 'id', $inboundFloor])->andWhere(['not', ['ticketId' => null]])->column();
    $ticketIds = array_unique(array_merge($ticketIds, Ticket::find()->isSpam(null)->requesterEmail($senders)->limit(null)->ids()));

    foreach ($ticketIds as $id) {
        foreach ($plugin->tickets->getAttachments((int)$id) as $attachment) {
            if ($attachment->getAsset() !== null) {
                Craft::$app->getElements()->deleteElement($attachment->getAsset(), true);
            }
        }

        $ticket = Ticket::find()->id((int)$id)->isSpam(null)->one();

        if ($ticket !== null) {
            Craft::$app->getElements()->deleteElement($ticket, true);
        }
    }

    Craft::$app->getDb()->createCommand()->delete(Table::INBOUND, ['>', 'id', $inboundFloor])->execute();
    Craft::$app->getDb()->createCommand()->delete('{{%queue}}', ['>', 'id', $queueFloor])->execute();
    craft\helpers\FileHelper::removeDirectory($tempDir);
    echo '  removed ' . count($ticketIds) . " tickets and their rows\n";
}

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
