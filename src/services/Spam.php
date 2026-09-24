<?php

declare(strict_types=1);

namespace justinholtweb\csr\services;

use Craft;
use craft\base\Component;
use craft\db\Query;
use craft\helpers\App;
use craft\helpers\Db;
use craft\helpers\Html;
use craft\helpers\Json;
use craft\helpers\Template;
use DateTime;
use justinholtweb\csr\elements\Ticket;
use justinholtweb\csr\helpers\Visitor;
use justinholtweb\csr\models\Settings;
use justinholtweb\csr\Plugin;
use justinholtweb\csr\records\Table;
use Throwable;
use Twig\Markup;

/**
 * Four cheap checks in front of the only anonymous write path in the plugin.
 *
 * Ordered by cost: a hidden field, a signed timestamp, a rate limit, and only then an outbound
 * request to a CAPTCHA provider — so the overwhelming majority of junk is refused without CSR
 * touching the network.
 *
 * **Every refusal returns the same message.** A form that says "you submitted that too fast" is
 * telling somebody exactly which check to work around, and the person reading it is never a
 * customer.
 *
 * The honeypot field's *name* is configurable because a fixed one is a fixed signature, and the
 * name is refused if it collides with `token`, `p` or `action` — Craft's own request parameters,
 * which are rejected before any controller runs.
 */
class Spam extends Component
{
    public const REASON_HONEYPOT = 'honeypot';
    public const REASON_TOO_FAST = 'tooFast';
    public const REASON_RATE_LIMIT = 'rateLimit';
    public const REASON_CAPTCHA = 'captcha';

    /** A new ticket from the support form. */
    public const KIND_TICKET = 'ticket';

    /** A reply from the customer's own ticket page. */
    public const KIND_REPLY = 'reply';

    /**
     * Whether this submission should be refused, and why — for the log, never for the visitor.
     *
     * Replies and new tickets are rate limited separately: somebody who raised five tickets
     * during an outage must still be able to answer the desk on each of them.
     *
     * @param array<string, mixed> $posted
     */
    public function check(array $posted, string $email = '', string $kind = self::KIND_TICKET): ?string
    {
        $settings = Plugin::getInstance()->getSettings();

        if (trim((string)($posted[$settings->honeypotField] ?? '')) !== '') {
            return self::REASON_HONEYPOT;
        }

        if (!$this->checkTimestamp($posted, $settings)) {
            return self::REASON_TOO_FAST;
        }

        $withinLimit = $kind === self::KIND_REPLY
            ? $this->checkReplyRateLimit($email, $settings)
            : $this->checkRateLimit($email, $settings);

        if (!$withinLimit) {
            return self::REASON_RATE_LIMIT;
        }

        if (!$this->checkCaptcha($posted, $settings)) {
            return self::REASON_CAPTCHA;
        }

        return null;
    }

    /** What the visitor is told, whichever check caught them. */
    public function refusalMessage(): string
    {
        return Craft::t('csr', 'We couldn’t send that. Please try again in a moment.');
    }

    /**
     * A signed "the form was rendered at" stamp.
     *
     * Signed rather than trusted, so the value cannot simply be back-dated, and validated as a
     * *minimum* elapsed time rather than a maximum: a genuine person filling in a support form
     * takes longer than three seconds, and a support form left open on a tab for an hour while
     * somebody gathers screenshots is the most legitimate submission there is.
     *
     * @param array<string, mixed> $posted
     */
    private function checkTimestamp(array $posted, Settings $settings): bool
    {
        if ($settings->minSubmitSeconds <= 0) {
            return true;
        }

        $value = (string)($posted['csrStamp'] ?? '');

        if ($value === '') {
            return false;
        }

        $rendered = Craft::$app->getSecurity()->validateData($value);

        if ($rendered === false) {
            return false;
        }

        return (time() - (int)$rendered) >= $settings->minSubmitSeconds;
    }

    /** The stamp to put in the form. */
    public function stamp(): string
    {
        return Craft::$app->getSecurity()->hashData((string)time());
    }

    /**
     * How many tickets this address has raised in the last hour.
     *
     * Keyed on the email address rather than the IP: a company behind one office NAT is one IP and
     * forty people, and a support desk that refuses the fifth person in an outage is refusing
     * exactly the traffic it exists for.
     */
    private function checkRateLimit(string $email, Settings $settings): bool
    {
        if ($settings->rateLimit <= 0 || $email === '') {
            return true;
        }

        $since = (new DateTime())->modify('-1 hour');

        $count = (int)Ticket::find()
            ->requesterEmail($email)
            ->isSpam(null)
            ->submittedAfter($since)
            ->count();

        return $count < $settings->rateLimit;
    }

    /**
     * How many replies this address has posted from ticket pages in the last hour.
     *
     * Counted from the replies themselves rather than the tickets raised — the link in a
     * notification is reachable by anybody it is forwarded to, so the reply box needs a ceiling,
     * but not one that a customer who raised several tickets has already spent.
     */
    private function checkReplyRateLimit(string $email, Settings $settings): bool
    {
        if ($settings->replyRateLimit <= 0 || $email === '') {
            return true;
        }

        $since = (new DateTime())->modify('-1 hour');

        $count = (int)(new Query())
            ->from(['replies' => Table::REPLIES])
            ->innerJoin(['tickets' => Table::TICKETS], '[[tickets.id]] = [[replies.ticketId]]')
            ->where([
                'tickets.requesterEmail' => $email,
                'replies.byAgent' => false,
                'replies.isNote' => false,
            ])
            ->andWhere(['>=', 'replies.dateCreated', Db::prepareDateForDb($since)])
            ->count();

        return $count < $settings->replyRateLimit;
    }

    /**
     * Ask the provider, if one is configured.
     *
     * Fails **open** on a network error: a CAPTCHA provider being down must not mean nobody can
     * reach support. The cost of that trade is some junk on the day Cloudflare has an outage; the
     * cost of the other trade is a customer who cannot report that your checkout is broken.
     *
     * @param array<string, mixed> $posted
     */
    private function checkCaptcha(array $posted, Settings $settings): bool
    {
        $secret = $this->captchaSecret($settings);

        if ($settings->captchaProvider === Settings::CAPTCHA_NONE || $secret === '') {
            return true;
        }

        $field = $settings->captchaProvider === Settings::CAPTCHA_TURNSTILE
            ? 'cf-turnstile-response'
            : 'g-recaptcha-response';

        $response = (string)($posted[$field] ?? '');

        if ($response === '') {
            return false;
        }

        $endpoint = $settings->captchaProvider === Settings::CAPTCHA_TURNSTILE
            ? 'https://challenges.cloudflare.com/turnstile/v0/siteverify'
            : 'https://www.google.com/recaptcha/api/siteverify';

        try {
            $result = Craft::createGuzzleClient(['timeout' => 5])->post($endpoint, [
                'form_params' => [
                    'secret' => $secret,
                    'response' => $response,
                    'remoteip' => Craft::$app->getRequest()->getUserIP(),
                ],
            ]);

            $body = Json::decodeIfJson((string)$result->getBody());

            return is_array($body) && ($body['success'] ?? false) === true;
        } catch (Throwable $exception) {
            Craft::warning(
                'The CSR CAPTCHA check could not reach its provider, so the submission was allowed: '
                . $exception->getMessage(),
                Plugin::LOG_CATEGORY,
            );

            return true;
        }
    }

    /**
     * The provider's widget, for the forms that are checked.
     *
     * Rendered by the server, with the provider's own script, so the forms CSR ships carry the
     * field {@see self::checkCaptcha()} reads. Without it, choosing a provider refused every
     * submission — the widget's response field was never posted. Empty when no provider is
     * configured, so a template can call it unconditionally.
     */
    public function widget(): Markup
    {
        $settings = Plugin::getInstance()->getSettings();
        $siteKey = trim((string)App::parseEnv((string)$settings->captchaSiteKey));

        if ($settings->captchaProvider === Settings::CAPTCHA_NONE || $siteKey === '' || $this->captchaSecret($settings) === '') {
            return Template::raw('');
        }

        [$class, $script] = $settings->captchaProvider === Settings::CAPTCHA_TURNSTILE
            ? ['cf-turnstile', 'https://challenges.cloudflare.com/turnstile/v0/api.js']
            : ['g-recaptcha', 'https://www.google.com/recaptcha/api.js'];

        Craft::$app->getView()->registerJsFile($script, ['async' => true, 'defer' => true]);

        return Template::raw(Html::tag('div', '', [
            'class' => ['csr-captcha', $class],
            'data' => ['sitekey' => $siteKey],
        ]));
    }

    /** The secret, with an environment variable resolved — the field offers them. */
    private function captchaSecret(Settings $settings): string
    {
        return trim((string)App::parseEnv((string)$settings->captchaSecret));
    }

    /** Write a refusal to the log with enough detail to tune the checks, and nothing more. */
    public function logRefusal(string $reason, string $email): void
    {
        Craft::info(sprintf(
            'CSR refused a support submission (%s) from %s.',
            $reason,
            Visitor::hash($email !== '' ? $email : (string)Craft::$app->getRequest()->getUserIP()),
        ), Plugin::LOG_CATEGORY);
    }
}
