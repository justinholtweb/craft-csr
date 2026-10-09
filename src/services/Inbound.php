<?php

declare(strict_types=1);

namespace justinholtweb\csr\services;

use Craft;
use craft\base\Component;
use craft\db\Migration;
use craft\db\Query;
use craft\helpers\Db;
use craft\helpers\FileHelper;
use craft\helpers\Html;
use craft\helpers\Json;
use craft\helpers\Queue;
use craft\mail\Message;
use DateTime;
use finfo;
use justinholtweb\csr\elements\Ticket;
use justinholtweb\csr\enums\TicketSource;
use justinholtweb\csr\events\InboundEmailEvent;
use justinholtweb\csr\jobs\ProcessInboundEmail;
use justinholtweb\csr\mail\Addresses;
use justinholtweb\csr\mail\InboundAttachment;
use justinholtweb\csr\mail\InboundMessage;
use justinholtweb\csr\mail\InboundRequest;
use justinholtweb\csr\mail\LoopGuard;
use justinholtweb\csr\mail\MimeParser;
use justinholtweb\csr\mail\providers\Mailgun;
use justinholtweb\csr\mail\providers\Postmark;
use justinholtweb\csr\mail\providers\ProviderInterface;
use justinholtweb\csr\mail\providers\SendGrid;
use justinholtweb\csr\mail\ReplyParser;
use justinholtweb\csr\models\Edition;
use justinholtweb\csr\models\Settings;
use justinholtweb\csr\Plugin;
use justinholtweb\csr\records\Table;
use RuntimeException;
use Throwable;
use yii\db\IntegrityException;

/**
 * Email in: provider webhooks and an IMAP mailbox, turned into tickets and replies.
 *
 * The family's reference for inbound email (see "Inbound email" in CLAUDE.md). Everything in
 * `src/mail/` is plain PHP and copies across unchanged; this class is the glue, and the part
 * marked "What CSR does with it" is the only part a port rewrites.
 *
 * ## Two halves, a queue between them
 *
 * {@see receive()} is the webhook: verify the provider's signature or credentials, parse, check
 * for a duplicate and the sender's rate, copy the attachments somewhere durable, write a row, queue
 * {@see ProcessInboundEmail}, answer 200. Nothing slow happens before the provider has its answer,
 * because a slow answer is a retry and every retry is another copy.
 *
 * {@see process()} is the job: the loop guard, finding the ticket (reply token first, then the
 * `In-Reply-To`/`References` headers), checking the sender is the ticket's customer, and then a
 * reply or a new ticket.
 *
 * ## Finding the ticket
 *
 * Exact matches only, twice over. The reply address carries a 32-character token that is looked
 * up by equality and compared again with `hash_equals()`; the headers are looked up by the SHA-256
 * of each Message-ID CSR has sent or received on the ticket. Nothing is matched on subject lines
 * or "#1234" in the text — anybody can type a ticket number.
 */
class Inbound extends Component
{
    /** @see InboundEmailEvent Cancel it to drop the email, or change the message. */
    public const EVENT_BEFORE_PROCESS = 'beforeProcess';

    /** @see InboundEmailEvent */
    public const EVENT_AFTER_PROCESS = 'afterProcess';

    public const STATUS_QUEUED = 'queued';
    public const STATUS_TICKET = 'ticket';
    public const STATUS_REPLY = 'reply';
    public const STATUS_IGNORED = 'ignored';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_RATE_LIMITED = 'rateLimited';
    public const STATUS_FAILED = 'failed';

    public const RESULT_ACCEPTED = 'accepted';
    public const RESULT_DUPLICATE = 'duplicate';
    public const RESULT_RATE_LIMITED = 'rateLimited';
    public const RESULT_REJECTED = 'rejected';

    /** The longest text and HTML kept from one email. Past this it is an attachment, not a reply. */
    public const MAX_TEXT_BYTES = 65536;
    public const MAX_HTML_BYTES = 262144;

    private const NONCE_KEY = 'csr:inbound:nonce:';

    // ------------------------------------------------------------------------------- switches

    public function isEnabled(): bool
    {
        $plugin = Plugin::getInstance();

        return $plugin->getSettings()->inboundEnabled && Edition::allowsInboundEmail($plugin->isPro());
    }

    /** The desk's address, resolved, or null when it is not set to a usable address. */
    public function getAddress(): ?string
    {
        return Addresses::normalize($this->settings()->env('inboundAddress'));
    }

    /** @return array<string, ProviderInterface> */
    public function getProviders(): array
    {
        $settings = $this->settings();
        $cache = Craft::$app->getCache();

        return [
            Postmark::handle() => new Postmark(
                $settings->env('postmarkUsername'),
                $settings->env('postmarkPassword'),
            ),
            Mailgun::handle() => new Mailgun(
                $settings->env('mailgunSigningKey'),
                $settings->inboundReplayWindow,
                static fn(string $token, int $ttl): bool => $cache->add(self::NONCE_KEY . hash('sha256', $token), 1, $ttl),
            ),
            SendGrid::handle() => new SendGrid(
                $settings->env('sendgridPublicKey'),
                $settings->env('sendgridUsername'),
                $settings->env('sendgridPassword'),
                $settings->inboundReplayWindow,
            ),
        ];
    }

    public function getProvider(string $handle): ?ProviderInterface
    {
        return $this->getProviders()[$handle] ?? null;
    }

    // ---------------------------------------------------------------------------- the webhook

    /** The live request, as the providers read it. */
    public function requestFromCraft(\craft\web\Request $request): InboundRequest
    {
        $headers = [];

        foreach ($request->getHeaders()->toArray() as $name => $values) {
            $headers[strtolower((string)$name)] = is_array($values) ? implode(', ', $values) : (string)$values;
        }

        [$user, $password] = $request->getAuthCredentials();
        $files = [];

        foreach ($_FILES as $name => $file) {
            // Single files only: no provider posts arrays of files, and nothing else is read.
            if (is_array($file) && is_string($file['tmp_name'] ?? null)) {
                $files[(string)$name] = [
                    'name' => (string)$file['name'],
                    'type' => (string)$file['type'],
                    'tmp_name' => $file['tmp_name'],
                    'size' => (int)$file['size'],
                    'error' => (int)$file['error'],
                ];
            }
        }

        return new InboundRequest(
            $headers,
            $request->getRawBody(),
            $_POST,
            $files,
            $user,
            $password,
            $this->tempDir(),
        );
    }

    /**
     * Verify, parse and accept one webhook delivery.
     *
     * @return array{status: int, result: string, id: int|null}
     */
    public function receive(string $handle, InboundRequest $request, ?int $now = null): array
    {
        $provider = $this->getProvider($handle);

        if ($provider === null) {
            return ['status' => 404, 'result' => 'unknown provider', 'id' => null];
        }

        // Refused, not waved through: an endpoint with no secret set is an endpoint anybody can
        // post tickets to. 403 also tells Postmark to stop retrying.
        if (!$provider->isConfigured()) {
            Craft::warning("Inbound email from $handle was refused: no secret is configured for it.", Plugin::LOG_CATEGORY);

            return ['status' => 403, 'result' => 'not configured', 'id' => null];
        }

        $reason = $provider->verify($request, $now ?? time());

        if ($reason !== null) {
            Craft::warning("Inbound email from $handle failed verification: $reason.", Plugin::LOG_CATEGORY);
            $this->cleanupRequestFiles($request);

            return ['status' => 401, 'result' => 'unauthorized', 'id' => null];
        }

        try {
            $message = $provider->parse($request);
        } catch (Throwable $exception) {
            Craft::warning("Inbound email from $handle could not be parsed: " . $exception->getMessage(), Plugin::LOG_CATEGORY);
            $this->cleanupRequestFiles($request);

            return ['status' => 400, 'result' => 'unreadable', 'id' => null];
        }

        $accepted = $this->accept($message);

        return ['status' => 200, 'result' => $accepted['result'], 'id' => $accepted['id']];
    }

    /**
     * A raw RFC 822 message — from the IMAP poll, `csr/inbound/import`, or a pipe from the MTA.
     *
     * @return array{result: string, id: int|null}
     */
    public function importRaw(string $raw, string $provider = 'import', bool $queue = true): array
    {
        $tempDir = $this->tempDir();
        $request = new InboundRequest(tempDir: $tempDir);
        $message = MimeParser::parse($raw, $request->writeTemp(...), $provider);

        return $this->accept($message, $queue);
    }

    /**
     * Take a parsed message in: dedupe, rate-limit, screen the attachments, store, queue.
     *
     * @return array{result: string, id: int|null}
     */
    public function accept(InboundMessage $message, bool $queue = true): array
    {
        $hash = $message->dedupeHash();
        $existing = (new Query())->select(['id'])->from([Table::INBOUND])->where(['messageHash' => $hash])->scalar();

        if ($existing !== false && $existing !== null) {
            $this->deleteFiles($message->attachments);

            return ['result' => self::RESULT_DUPLICATE, 'id' => (int)$existing];
        }

        if ($message->fromEmail === '' || Addresses::normalize($message->fromEmail) === null) {
            $this->deleteFiles($message->attachments);
            $id = $this->insertRow($message, $hash, self::STATUS_REJECTED, 'no usable sender address', null);

            return ['result' => self::RESULT_REJECTED, 'id' => $id];
        }

        if ($this->isRateLimited($message->fromEmail)) {
            $this->deleteFiles($message->attachments);
            $id = $this->insertRow($message, $hash, self::STATUS_RATE_LIMITED, 'more than the hourly limit from this sender', null);
            Craft::warning("Inbound email from {$message->fromEmail} was rate limited.", Plugin::LOG_CATEGORY);

            return ['result' => self::RESULT_RATE_LIMITED, 'id' => $id];
        }

        $message->text = mb_strcut($message->text, 0, self::MAX_TEXT_BYTES, 'UTF-8');
        $message->html = mb_strcut($message->html, 0, self::MAX_HTML_BYTES, 'UTF-8');

        [$kept, $dropped] = $this->screenAttachments($message->attachments);
        $message->attachments = $this->moveToStorage($kept, $hash);

        $payload = $message->toArray();
        $payload['dropped'] = $dropped;

        $id = $this->insertRow($message, $hash, self::STATUS_QUEUED, null, $payload);

        if ($id === null) {
            // Lost a race with another delivery of the same message.
            $this->deleteFiles($message->attachments);
            $existing = (new Query())->select(['id'])->from([Table::INBOUND])->where(['messageHash' => $hash])->scalar();

            return ['result' => self::RESULT_DUPLICATE, 'id' => $existing !== false ? (int)$existing : null];
        }

        if ($queue) {
            Queue::push(new ProcessInboundEmail(['inboundId' => $id]));
        } else {
            $this->process($id);
        }

        return ['result' => self::RESULT_ACCEPTED, 'id' => $id];
    }

    /** @param array<string, mixed>|null $payload */
    private function insertRow(InboundMessage $message, string $hash, string $status, ?string $reason, ?array $payload): ?int
    {
        try {
            Db::insert(Table::INBOUND, [
                'provider' => mb_substr($message->provider ?: 'unknown', 0, 16),
                'messageHash' => $hash,
                'messageId' => $message->messageId !== null ? mb_substr($message->messageId, 0, 255) : null,
                'fromEmail' => mb_substr(strtolower($message->fromEmail), 0, 255),
                'subject' => mb_substr($message->subject, 0, 255),
                'status' => $status,
                'reason' => $reason,
                'payload' => $payload !== null ? Json::encode($payload) : null,
                'dateProcessed' => $status === self::STATUS_QUEUED ? null : Db::prepareDateForDb(new DateTime()),
            ]);
        } catch (IntegrityException) {
            return null;
        }

        // Read back by the unique key rather than `getLastInsertID()`, which needs a sequence
        // name on Postgres.
        return (int)(new Query())->select(['id'])->from([Table::INBOUND])->where(['messageHash' => $hash])->scalar();
    }

    /** Whether this sender has already sent the hourly limit. */
    public function isRateLimited(string $fromEmail): bool
    {
        $limit = $this->settings()->inboundRateLimit;

        if ($limit <= 0) {
            return false;
        }

        $count = (int)(new Query())
            ->from([Table::INBOUND])
            ->where(['fromEmail' => strtolower($fromEmail)])
            ->andWhere(['>=', 'dateCreated', Db::prepareDateForDb(new DateTime('-1 hour'))])
            ->count();

        return $count >= $limit;
    }

    // ------------------------------------------------------------------------------ attachments

    /**
     * Which attachments may be kept, under the support form's own rules: attachments on and a
     * volume chosen, an allowed extension, under the size limit, no more than the count — and
     * content that is what its extension says it is, because `invoice.pdf` that is really a
     * Windows executable is exactly what arrives at a support address.
     *
     * @param list<InboundAttachment> $attachments
     * @return array{0: list<InboundAttachment>, 1: list<array{filename: string, reason: string}>}
     */
    public function screenAttachments(array $attachments): array
    {
        $settings = $this->settings();
        $kept = [];
        $dropped = [];
        $enabled = $settings->inboundAttachments && $settings->attachmentsEnabled && $settings->attachmentVolumeUid !== null;
        $allowed = $settings->getAllowedExtensions();
        $maxSize = $settings->getMaxFileSizeBytes();
        $maxCount = max(1, $settings->maxFilesPerReply);

        foreach ($attachments as $attachment) {
            $reason = match (true) {
                !$enabled => 'attachments are off',
                !in_array($attachment->extension(), $allowed, true) => 'file type not allowed',
                $attachment->size <= 0 || !is_file($attachment->path) => 'empty file',
                $attachment->size > $maxSize || filesize($attachment->path) > $maxSize => 'too large',
                count($kept) >= $maxCount => 'too many attachments',
                !self::contentMatchesExtension($attachment->path, $attachment->extension()) => 'content does not match its extension',
                default => null,
            };

            if ($reason === null) {
                $kept[] = $attachment;
                continue;
            }

            $dropped[] = ['filename' => mb_substr($attachment->filename, 0, 200), 'reason' => $reason];
            @unlink($attachment->path);
        }

        return [$kept, $dropped];
    }

    /** Sniff the bytes and ask whether that kind of file is allowed to carry this extension. */
    public static function contentMatchesExtension(string $path, string $extension): bool
    {
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($path) ?: 'application/octet-stream';

        if (in_array($extension, FileHelper::getExtensionsByMimeType($mime), true)) {
            return true;
        }

        // libmagic's names for the formats it cannot name more precisely.
        $families = [
            'text/' => ['txt', 'log', 'csv', 'md', 'json', 'xml', 'eml', 'ics', 'vcf'],
            'application/zip' => ['zip', 'docx', 'xlsx', 'pptx', 'odt', 'ods', 'odp', 'pages', 'numbers', 'key'],
            'application/x-ole-storage' => ['doc', 'xls', 'ppt', 'msg'],
            'application/cdfv2' => ['doc', 'xls', 'ppt', 'msg'],
            'message/rfc822' => ['eml'],
            'image/jpeg' => ['jpg', 'jpeg', 'jfif'],
        ];

        foreach ($families as $prefix => $extensions) {
            if (str_starts_with($mime, $prefix) && in_array($extension, $extensions, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Copy kept attachments out of the request's temp space into storage that outlives it.
     *
     * @param list<InboundAttachment> $attachments
     * @return list<InboundAttachment>
     */
    private function moveToStorage(array $attachments, string $hash): array
    {
        if ($attachments === []) {
            return [];
        }

        $dir = $this->storageDir() . DIRECTORY_SEPARATOR . substr($hash, 0, 24);
        FileHelper::createDirectory($dir);
        $moved = [];

        foreach ($attachments as $index => $attachment) {
            $target = $dir . DIRECTORY_SEPARATOR . $index;

            if (@copy($attachment->path, $target)) {
                @unlink($attachment->path);
                $attachment->path = $target;
                $moved[] = $attachment;
            }
        }

        return $moved;
    }

    /** @param list<InboundAttachment> $attachments */
    private function deleteFiles(array $attachments): void
    {
        foreach ($attachments as $attachment) {
            if ($attachment->path !== '' && is_file($attachment->path)) {
                @unlink($attachment->path);
            }
        }
    }

    private function cleanupRequestFiles(InboundRequest $request): void
    {
        foreach ($request->files as $file) {
            if (is_file($file['tmp_name'])) {
                @unlink($file['tmp_name']);
            }
        }
    }

    public function storageDir(): string
    {
        return Craft::$app->getPath()->getStoragePath() . DIRECTORY_SEPARATOR . 'csr-inbound';
    }

    private function tempDir(): string
    {
        return Craft::$app->getPath()->getTempPath() . DIRECTORY_SEPARATOR . 'csr-inbound';
    }

    // ============================================================== What CSR does with it
    //
    // Everything below is CSR's. A port keeps process()'s shape — loop guard, find, verify the
    // sender, act, record — and rewrites what "act" means.

    /**
     * Turn one stored email into a ticket or a reply. Idempotent: anything no longer queued is
     * left as it is.
     *
     * @return string The row's status afterwards.
     */
    public function process(int $id): string
    {
        $row = (new Query())->from([Table::INBOUND])->where(['id' => $id])->one();

        if (!$row) {
            return self::STATUS_FAILED;
        }

        if ($row['status'] !== self::STATUS_QUEUED) {
            return (string)$row['status'];
        }

        // Claim it, so two workers picking up two copies of the job do the work once.
        if (Db::update(Table::INBOUND, ['status' => 'processing'], ['id' => $id, 'status' => self::STATUS_QUEUED]) !== 1) {
            return (string)(new Query())->select(['status'])->from([Table::INBOUND])->where(['id' => $id])->scalar();
        }

        $payload = Json::decodeIfJson((string)$row['payload']);
        $payload = is_array($payload) ? $payload : [];
        $message = InboundMessage::fromArray($payload);
        $dropped = is_array($payload['dropped'] ?? null) ? $payload['dropped'] : [];

        try {
            [$status, $reason, $ticket, $reply] = $this->handle($message, $id, $dropped);
        } catch (Throwable $exception) {
            Craft::error("Inbound email #$id could not be processed: " . $exception->getMessage(), Plugin::LOG_CATEGORY);
            Db::update(Table::INBOUND, [
                'status' => self::STATUS_FAILED,
                'reason' => mb_substr($exception->getMessage(), 0, 255),
                'attempts' => new \yii\db\Expression('[[attempts]] + 1'),
                'dateProcessed' => Db::prepareDateForDb(new DateTime()),
            ], ['id' => $id]);

            // Kept for `csr/inbound/retry`: a failure is usually the database or the volume, and
            // the email is still worth having once that is fixed.
            return self::STATUS_FAILED;
        }

        $this->deleteFiles($message->attachments);
        @rmdir($this->storageDir() . DIRECTORY_SEPARATOR . substr((string)$row['messageHash'], 0, 24));

        Db::update(Table::INBOUND, [
            'status' => $status,
            'reason' => $reason !== null ? mb_substr($reason, 0, 255) : null,
            'ticketId' => $ticket?->id,
            'replyId' => $reply?->id,
            'attempts' => new \yii\db\Expression('[[attempts]] + 1'),
            'dateProcessed' => Db::prepareDateForDb(new DateTime()),
            // The content has done its job; the row stays as the record of what happened.
            'payload' => null,
        ], ['id' => $id]);

        if ($this->hasEventHandlers(self::EVENT_AFTER_PROCESS)) {
            $this->trigger(self::EVENT_AFTER_PROCESS, new InboundEmailEvent([
                'message' => $message,
                'inboundId' => $id,
                'ticket' => $ticket,
                'reply' => $reply,
                'outcome' => $status,
            ]));
        }

        return $status;
    }

    /**
     * @param list<array{filename: string, reason: string}> $dropped
     * @return array{0: string, 1: string|null, 2: Ticket|null, 3: \justinholtweb\csr\models\Reply|null}
     */
    private function handle(InboundMessage $message, int $id, array $dropped): array
    {
        $settings = $this->settings();
        $loop = LoopGuard::reason($message, $this->ownAddresses());

        if ($loop !== null) {
            return [self::STATUS_IGNORED, 'automated mail: ' . $loop, null, null];
        }

        [$ticket, $via] = $this->findTicket($message);

        if ($ticket !== null && !$this->senderMatches($ticket, $message)) {
            if ($settings->inboundUnverifiedSender === Settings::UNVERIFIED_REJECT) {
                return [self::STATUS_REJECTED, "sent to ticket #{$ticket->number} by somebody other than its customer", null, null];
            }

            // Somebody else writing about the same thing: their own ticket, never a line in the
            // customer's conversation.
            $ticket = null;
        }

        $event = new InboundEmailEvent(['message' => $message, 'inboundId' => $id, 'ticket' => $ticket]);

        if ($this->hasEventHandlers(self::EVENT_BEFORE_PROCESS)) {
            $this->trigger(self::EVENT_BEFORE_PROCESS, $event);
        }

        if (!$event->isValid) {
            return [self::STATUS_IGNORED, 'cancelled by an event handler', null, null];
        }

        $ticket = $event->ticket;

        return $ticket !== null
            ? $this->appendReply($ticket, $message, $dropped, (string)$via)
            : $this->raiseTicket($message, $dropped);
    }

    /**
     * @param list<array{filename: string, reason: string}> $dropped
     * @return array{0: string, 1: string|null, 2: Ticket|null, 3: \justinholtweb\csr\models\Reply|null}
     */
    private function appendReply(Ticket $ticket, InboundMessage $message, array $dropped, string $via): array
    {
        $plugin = Plugin::getInstance();
        $text = ReplyParser::newText($message->text, $message->html);

        if ($text === '' && $message->attachments === []) {
            return [self::STATUS_IGNORED, 'nothing new in the reply', $ticket, null];
        }

        $reply = $plugin->replies->post($ticket, [
            'body' => $text !== '' ? self::textToHtml($text) : Html::tag('p', Html::encode(Craft::t('csr', '(Sent attachments only.)'))),
            // Never the desk's: whatever the email says, it came from outside.
            'byAgent' => false,
            'isNote' => false,
            'userId' => null,
            'authorName' => $message->fromName ?? $ticket->requesterName,
            'authorEmail' => $message->fromEmail,
            'source' => TicketSource::Email->value,
        ]);

        if ($reply === null) {
            return [self::STATUS_IGNORED, 'nothing new in the reply', $ticket, null];
        }

        foreach ($message->attachments as $attachment) {
            $plugin->tickets->storeAttachment($ticket, $attachment->path, $attachment->filename, $reply->id);
        }

        $this->noteDropped($ticket, $dropped);
        $this->recordThread($ticket, $message->messageId, 'in');

        return [self::STATUS_REPLY, 'matched by ' . $via, $ticket, $reply];
    }

    /**
     * @param list<array{filename: string, reason: string}> $dropped
     * @return array{0: string, 1: string|null, 2: Ticket|null, 3: null}
     */
    private function raiseTicket(InboundMessage $message, array $dropped): array
    {
        $plugin = Plugin::getInstance();

        if (!$this->settings()->inboundCreateTickets) {
            return [self::STATUS_REJECTED, 'new tickets by email are switched off', null, null];
        }

        // The whole of a new email is the message — a forward's quoted part is usually the point
        // — so only the HTML-to-text step applies, not the reply stripping.
        $text = trim($message->text) !== '' ? trim($message->text) : ReplyParser::htmlToText($message->html);

        $ticket = $plugin->tickets->submit([
            'name' => $message->fromName,
            'email' => $message->fromEmail,
            'subject' => $message->subject,
            'message' => $text,
            'source' => TicketSource::Email,
            // Nobody is signed in to an email, and the From line is not proof of an account.
            'requesterId' => null,
            'siteId' => (int)Craft::$app->getSites()->getPrimarySite()->id,
        ]);

        if ($ticket->hasErrors() || $ticket->id === null) {
            return [self::STATUS_REJECTED, 'not a valid ticket: ' . implode(' ', $ticket->getFirstErrors()), null, null];
        }

        foreach ($message->attachments as $attachment) {
            $plugin->tickets->storeAttachment($ticket, $attachment->path, $attachment->filename);
        }

        $this->noteDropped($ticket, $dropped);
        $this->recordThread($ticket, $message->messageId, 'in');

        return [self::STATUS_TICKET, null, $ticket, null];
    }

    /**
     * Which ticket an email belongs to, and how that was decided.
     *
     * @return array{0: Ticket|null, 1: string|null}
     */
    public function findTicket(InboundMessage $message): array
    {
        $base = $this->getAddress();
        $tokens = [];

        if ($base !== null) {
            foreach ($message->allRecipients() as $recipient) {
                $token = Addresses::tokenFrom($recipient, $base);

                if ($token !== null) {
                    $tokens[] = $token;
                }
            }
        }

        if ($message->mailboxHash !== null && Addresses::isToken($message->mailboxHash)) {
            $tokens[] = $message->mailboxHash;
        }

        foreach (array_unique($tokens) as $token) {
            $ticket = $this->ticketByToken($token);

            if ($ticket !== null) {
                return [$this->followMerge($ticket), 'reply address'];
            }
        }

        $hashes = array_map(static fn(string $id) => Addresses::messageHash($id), $message->threadIds());

        if ($hashes !== []) {
            $ticketId = (new Query())
                ->select(['ticketId'])
                ->from([Table::EMAIL_THREADS])
                ->where(['messageHash' => $hashes])
                ->orderBy(['id' => SORT_DESC])
                ->scalar();

            if ($ticketId !== false && $ticketId !== null) {
                /** @var Ticket|null $ticket */
                $ticket = Ticket::find()->id((int)$ticketId)->isSpam(null)->one();

                if ($ticket !== null) {
                    return [$this->followMerge($ticket), 'email headers'];
                }
            }
        }

        return [null, null];
    }

    public function ticketByToken(string $token): ?Ticket
    {
        $token = strtolower($token);

        if (!Addresses::isToken($token)) {
            return null;
        }

        /** @var Ticket|null $ticket */
        $ticket = Ticket::find()->replyToken($token)->isSpam(null)->one();

        return $ticket !== null && hash_equals((string)$ticket->replyToken, $token) ? $ticket : null;
    }

    /** A reply to a ticket that was merged lands on the ticket it was merged into. */
    private function followMerge(Ticket $ticket): Ticket
    {
        $seen = [];

        while ($ticket->mergedIntoId !== null && !isset($seen[$ticket->id])) {
            $seen[$ticket->id] = true;
            /** @var Ticket|null $target */
            $target = Ticket::find()->id($ticket->mergedIntoId)->isSpam(null)->one();

            if ($target === null) {
                break;
            }

            $ticket = $target;
        }

        return $ticket;
    }

    /** Whether the email came from the ticket's own customer. */
    public function senderMatches(Ticket $ticket, InboundMessage $message): bool
    {
        $from = Addresses::normalize($message->fromEmail);
        $requester = Addresses::normalize($ticket->requesterEmail);

        return $from !== null && $requester !== null && hash_equals($requester, $from);
    }

    /** @param list<array{filename: string, reason: string}> $dropped */
    private function noteDropped(Ticket $ticket, array $dropped): void
    {
        if ($dropped === []) {
            return;
        }

        $lines = array_map(
            static fn(array $file) => Html::tag('li', Html::encode($file['filename'] . ' — ' . $file['reason'])),
            $dropped,
        );

        Plugin::getInstance()->replies->post($ticket, [
            'body' => Html::tag('p', Html::encode(Craft::t('csr', 'Attachments in the customer’s email that were not kept:'))) . Html::tag('ul', implode('', $lines)),
            'isNote' => true,
            'byAgent' => true,
            'userId' => null,
            'source' => TicketSource::Email->value,
            'notify' => false,
        ]);
    }

    /** Plain text to the paragraphs a reply is stored as, escaped on the way. */
    public static function textToHtml(string $text): string
    {
        $paragraphs = preg_split("/\n{2,}/", trim($text)) ?: [];

        return implode('', array_map(
            static fn(string $paragraph) => Html::tag('p', nl2br(Html::encode($paragraph), false)),
            $paragraphs,
        ));
    }

    /** @return list<string> Every address mail must never be taken from. */
    public function ownAddresses(): array
    {
        $own = $this->settings()->getIgnoredAddresses();

        if (($address = $this->getAddress()) !== null) {
            $own[] = $address;
        }

        $system = Addresses::normalize((string)\craft\helpers\App::mailSettings()->fromEmail);

        if ($system !== null) {
            $own[] = $system;
        }

        return array_values(array_unique($own));
    }

    // -------------------------------------------------------------------------- email going out

    /** `support+<token>@example.com` for this ticket, when inbound email is on. */
    public function replyAddressFor(Ticket $ticket): ?string
    {
        $base = $this->getAddress();

        if (!$this->isEnabled() || $base === null || $ticket->replyToken === null) {
            return null;
        }

        return Addresses::replyAddress($base, $ticket->replyToken);
    }

    /**
     * Address an email to a customer so their reply comes back to the ticket.
     *
     * The reply address goes in `Reply-To`; a Message-ID of CSR's own is set and remembered, so a
     * client that drops the tagged address but keeps `In-Reply-To` still finds the ticket; the
     * first email the customer sent is referenced so their client threads the conversation; and an
     * automatic acknowledgement says it is one (RFC 3834), so their out-of-office leaves it alone.
     */
    public function prepareOutgoing(Message $message, Ticket $ticket, bool $automatic = false): void
    {
        $address = $this->replyAddressFor($ticket);

        if ($address === null || $ticket->id === null) {
            return;
        }

        $message->setReplyTo($address);
        $headers = $message->getSymfonyEmail()->getHeaders();
        $domain = explode('@', $address, 2)[1];
        $messageId = 'csr.' . bin2hex(random_bytes(12)) . '@' . $domain;

        $headers->remove('Message-ID');
        $headers->addIdHeader('Message-ID', $messageId);

        $first = (new Query())
            ->select(['messageId'])
            ->from([Table::EMAIL_THREADS])
            ->where(['ticketId' => $ticket->id, 'direction' => 'in'])
            ->andWhere(['not', ['messageId' => null]])
            ->orderBy(['id' => SORT_ASC])
            ->scalar();

        if (is_string($first) && $first !== '' && !$headers->has('In-Reply-To')) {
            $headers->addIdHeader('In-Reply-To', $first);
            $headers->addIdHeader('References', [$first]);
        }

        if ($automatic && !$headers->has('Auto-Submitted')) {
            $headers->addTextHeader('Auto-Submitted', 'auto-replied');
        }

        $this->recordThread($ticket, $messageId, 'out');
    }

    /** Remember that a Message-ID belongs to a ticket. */
    public function recordThread(Ticket $ticket, ?string $messageId, string $direction): void
    {
        $messageId = Addresses::normalizeMessageId($messageId);

        if ($messageId === null || $ticket->id === null) {
            return;
        }

        try {
            Db::insert(Table::EMAIL_THREADS, [
                'ticketId' => $ticket->id,
                'messageHash' => Addresses::messageHash($messageId),
                'messageId' => mb_substr($messageId, 0, 255),
                'direction' => $direction === 'out' ? 'out' : 'in',
            ]);
        } catch (IntegrityException) {
            // Already known. The first ticket to claim a Message-ID keeps it.
        }
    }

    // ----------------------------------------------------------------------------------- IMAP

    /** Whether the poll can run on this PHP at all. */
    public static function imapAvailable(): bool
    {
        return function_exists('imap_open');
    }

    /**
     * Take unread messages from the configured mailbox.
     *
     * Each message is marked read (or deleted) only after it has been stored, so a failure halfway
     * through leaves the rest for the next run rather than losing them.
     *
     * @return array{fetched: int, accepted: int, duplicates: int, rejected: int}
     * @throws RuntimeException when the extension is missing or the mailbox cannot be opened.
     */
    public function poll(int $limit = 50, bool $queue = true): array
    {
        if (!self::imapAvailable()) {
            throw new RuntimeException(Craft::t('csr', 'PHP’s imap extension is not installed, so CSR cannot read a mailbox. Point your mail provider’s inbound webhook at CSR instead, or install the extension (PECL “imap” on PHP 8.4+).'));
        }

        $settings = $this->settings();
        $host = $settings->env('imapHost');

        if ($host === '' || $settings->env('imapUsername') === '') {
            throw new RuntimeException(Craft::t('csr', 'No IMAP mailbox is configured. Set the host, username and password under CSR → Settings → Inbound email.'));
        }

        $flags = match ($settings->imapEncryption) {
            Settings::IMAP_SSL => '/imap/ssl',
            Settings::IMAP_TLS => '/imap/tls',
            default => '/imap/notls',
        };
        $mailbox = sprintf('{%s:%d%s}%s', $host, $settings->imapPort, $flags, $settings->imapMailbox ?: 'INBOX');

        $stream = @imap_open($mailbox, $settings->env('imapUsername'), $settings->env('imapPassword'), 0, 1);

        if ($stream === false) {
            $error = (string)imap_last_error();
            imap_errors();

            throw new RuntimeException(Craft::t('csr', 'Could not open the mailbox: {error}', ['error' => $error]));
        }

        $counts = ['fetched' => 0, 'accepted' => 0, 'duplicates' => 0, 'rejected' => 0];
        $uids = imap_search($stream, 'UNSEEN', SE_UID) ?: [];

        try {
            foreach (array_slice($uids, 0, max(1, $limit)) as $uid) {
                $raw = (string)imap_fetchheader($stream, (int)$uid, FT_UID) . (string)imap_body($stream, (int)$uid, FT_UID | FT_PEEK);
                $counts['fetched']++;

                $result = $this->importRaw($raw, 'imap', $queue)['result'];
                $key = match ($result) {
                    self::RESULT_ACCEPTED => 'accepted',
                    self::RESULT_DUPLICATE => 'duplicates',
                    default => 'rejected',
                };
                $counts[$key]++;

                if ($settings->imapAfterImport === Settings::IMAP_DELETE) {
                    imap_delete($stream, (string)$uid, FT_UID);
                } else {
                    imap_setflag_full($stream, (string)$uid, '\\Seen', ST_UID);
                }
            }

            if ($settings->imapAfterImport === Settings::IMAP_DELETE) {
                imap_expunge($stream);
            }
        } finally {
            imap_close($stream);
        }

        return $counts;
    }

    // ------------------------------------------------------------------------- housekeeping

    /**
     * Put failed emails back in the queue, and any a crashed worker left half-done for more than
     * an hour.
     *
     * @return int How many.
     */
    public function retryFailed(): int
    {
        $ids = (new Query())
            ->select(['id'])
            ->from([Table::INBOUND])
            ->where(['or',
                ['status' => self::STATUS_FAILED],
                ['and', ['status' => 'processing'], ['<', 'dateUpdated', Db::prepareDateForDb(new DateTime('-1 hour'))]],
            ])
            ->andWhere(['not', ['payload' => null]])
            ->column();

        foreach ($ids as $id) {
            Db::update(Table::INBOUND, ['status' => self::STATUS_QUEUED], ['id' => (int)$id]);
            Queue::push(new ProcessInboundEmail(['inboundId' => (int)$id]));
        }

        return count($ids);
    }

    /** Process whatever is still queued, here and now — for a site with no queue runner. */
    public function processQueued(int $limit = 100): int
    {
        $ids = (new Query())
            ->select(['id'])
            ->from([Table::INBOUND])
            ->where(['status' => self::STATUS_QUEUED])
            ->orderBy(['id' => SORT_ASC])
            ->limit($limit)
            ->column();

        foreach ($ids as $id) {
            $this->process((int)$id);
        }

        return count($ids);
    }

    /** @return list<array<string, mixed>> The latest rows, newest first, for the console. */
    public function recent(int $limit = 20): array
    {
        return array_values((new Query())
            ->select(['id', 'provider', 'fromEmail', 'subject', 'status', 'reason', 'ticketId', 'dateCreated'])
            ->from([Table::INBOUND])
            ->orderBy(['id' => SORT_DESC])
            ->limit($limit)
            ->all());
    }

    /** Drop the record of emails older than the search retention window. */
    public function prune(int $days = 90): int
    {
        return Db::delete(Table::INBOUND, [
            'and',
            ['not', ['status' => [self::STATUS_QUEUED, 'processing']]],
            ['<', 'dateCreated', Db::prepareDateForDb(new DateTime("-{$days} days"))],
        ]);
    }

    /** Shared by `Install` and the migration that adds the tables to an existing install. */
    public static function createTables(Migration $migration): void
    {
        $migration->createTable(Table::INBOUND, [
            'id' => $migration->primaryKey(),
            'provider' => $migration->string(16)->notNull(),
            // SHA-256 of the Message-ID, or of the content when there is none: the dedupe key.
            'messageHash' => $migration->char(64)->notNull(),
            'messageId' => $migration->string(),
            'fromEmail' => $migration->string()->notNull(),
            'subject' => $migration->string(),
            'status' => $migration->string(16)->notNull(),
            'reason' => $migration->string(),
            // The parsed message while it waits for the job; emptied once it has been handled.
            'payload' => $migration->mediumText(),
            'ticketId' => $migration->integer(),
            'replyId' => $migration->integer(),
            'attempts' => $migration->smallInteger()->unsigned()->notNull()->defaultValue(0),
            'dateProcessed' => $migration->dateTime(),
            'dateCreated' => $migration->dateTime()->notNull(),
            'dateUpdated' => $migration->dateTime()->notNull(),
            'uid' => $migration->uid(),
        ]);

        $migration->createIndex(null, Table::INBOUND, ['messageHash'], true);
        $migration->createIndex(null, Table::INBOUND, ['fromEmail', 'dateCreated'], false);
        $migration->createIndex(null, Table::INBOUND, ['status'], false);
        $migration->addForeignKey(null, Table::INBOUND, ['ticketId'], Table::TICKETS, ['id'], 'SET NULL', null);
        $migration->addForeignKey(null, Table::INBOUND, ['replyId'], Table::REPLIES, ['id'], 'SET NULL', null);

        $migration->createTable(Table::EMAIL_THREADS, [
            'id' => $migration->primaryKey(),
            'ticketId' => $migration->integer()->notNull(),
            'messageHash' => $migration->char(64)->notNull(),
            'messageId' => $migration->string(),
            // `in`: the customer sent it. `out`: CSR did.
            'direction' => $migration->string(3)->notNull(),
            'dateCreated' => $migration->dateTime()->notNull(),
            'dateUpdated' => $migration->dateTime()->notNull(),
            'uid' => $migration->uid(),
        ]);

        $migration->createIndex(null, Table::EMAIL_THREADS, ['messageHash'], true);
        $migration->createIndex(null, Table::EMAIL_THREADS, ['ticketId', 'direction'], false);
        $migration->addForeignKey(null, Table::EMAIL_THREADS, ['ticketId'], Table::TICKETS, ['id'], 'CASCADE', null);
    }

    private function settings(): Settings
    {
        return Plugin::getInstance()->getSettings();
    }
}
