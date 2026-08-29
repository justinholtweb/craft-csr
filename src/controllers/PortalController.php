<?php

declare(strict_types=1);

namespace justinholtweb\csr\controllers;

use Craft;
use craft\web\Controller;
use justinholtweb\csr\elements\Ticket;
use justinholtweb\csr\enums\TicketSource;
use justinholtweb\csr\helpers\Templates;
use justinholtweb\csr\models\Edition;
use justinholtweb\csr\Plugin;
use justinholtweb\csr\web\assets\kb\KbAsset;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Where a customer reads and replies to their own ticket.
 *
 * Two ways in, and both of them are authorisation:
 *
 * - **A reference**, the 32 random characters in the link in their email. The only way somebody
 *   without an account reaches their own ticket. The parameter is `csrRef`, never `token`: Craft
 *   reserves `token` for preview and share links and rejects an unrecognised one in
 *   `web\Application::init()` — before any controller runs — so every notification link would 400
 *   with "Invalid token" and nothing here would ever be reached.
 * - **Being signed in as the requester.** Checked against `requesterId`, not against the email
 *   address: two accounts can share an address over the life of a site, and an email match is not
 *   an identity.
 *
 * The portal asks for {@see Ticket::getPublicReplies()} rather than filtering in the template,
 * because an internal note reaching the customer is the one bug in this plugin that cannot be
 * walked back — so the filter lives in the query, where a site rewriting the template cannot lose
 * it.
 */
class PortalController extends Controller
{
    protected array|bool|int $allowAnonymous = true;

    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requireSiteRequest();

        if (!Plugin::getInstance()->getSettings()->portalEnabled) {
            throw new NotFoundHttpException();
        }

        return true;
    }

    public function actionView(): Response
    {
        $ticket = $this->authorize();
        $plugin = Plugin::getInstance();

        return $this->page('ticket', [
            'ticket' => $ticket,
            'replies' => $ticket->getPublicReplies(),
            'reference' => $ticket->reference,
            'canRate' => Edition::allowsSatisfactionSurvey($plugin->isPro())
                && $plugin->getSettings()->satisfactionEnabled
                && $ticket->resolvedAt !== null
                && $ticket->satisfaction === null,
        ]);
    }

    public function actionReply(): ?Response
    {
        $this->requirePostRequest();

        $ticket = $this->authorize();
        $plugin = Plugin::getInstance();
        $request = Craft::$app->getRequest();

        $body = trim((string)$request->getBodyParam('body', ''));

        if ($body === '') {
            Craft::$app->getSession()->setError(Craft::t('csr', 'Write something first.'));

            return null;
        }

        // Rate limited on the same terms as raising a ticket: the reply box on a ticket page is
        // reachable by anybody holding the reference, and a link forwarded to a mailing list is a
        // link a mailing list can flood.
        $refusal = $plugin->spam->check($request->getBodyParams(), $ticket->requesterEmail);

        if ($refusal !== null) {
            $plugin->spam->logRefusal($refusal, $ticket->requesterEmail);
            Craft::$app->getSession()->setError($plugin->spam->refusalMessage());

            return null;
        }

        $plugin->replies->post($ticket, [
            'body' => $body,
            // Never an agent, whatever the request says: this endpoint is reachable by a stranger
            // holding a link, and a reply marked as the desk's would stop the first-response
            // clock and tell the customer that somebody had answered them.
            'byAgent' => false,
            'isNote' => false,
            'userId' => Craft::$app->getUser()->getId(),
            'authorName' => $ticket->requesterName,
            'authorEmail' => $ticket->requesterEmail,
            'source' => TicketSource::Portal->value,
        ]);

        Craft::$app->getSession()->setNotice(Craft::t('csr', 'Thanks — your reply has been added.'));

        return $this->redirect($ticket->getPortalUrl() ?? '/');
    }

    /** "How did we do?" */
    public function actionRate(): ?Response
    {
        $this->requirePostRequest();

        $ticket = $this->authorize();
        $plugin = Plugin::getInstance();

        if (!Edition::allowsSatisfactionSurvey($plugin->isPro())) {
            throw new ForbiddenHttpException();
        }

        $score = (int)Craft::$app->getRequest()->getRequiredBodyParam('score');
        $comment = Craft::$app->getRequest()->getBodyParam('comment');

        $plugin->tickets->rate($ticket, $score, $comment !== null ? (string)$comment : null);

        Craft::$app->getSession()->setNotice(Craft::t('csr', 'Thank you.'));

        return $this->redirect($ticket->getPortalUrl() ?? '/');
    }

    /** Stream an attachment to the person whose ticket it is on. */
    public function actionDownload(int $attachmentId): Response
    {
        $ticket = $this->authorize();
        $attachment = Plugin::getInstance()->tickets->getAttachmentById($attachmentId);

        // The attachment has to belong to *this* ticket. Without that check the reference to one
        // ticket would be a key to every file on the desk.
        if ($attachment === null || $attachment->ticketId !== $ticket->id) {
            throw new NotFoundHttpException();
        }

        $asset = $attachment->getAsset();

        if ($asset === null) {
            throw new NotFoundHttpException(Craft::t('csr', 'That file is no longer here.'));
        }

        return Craft::$app->getResponse()->sendStreamAsFile(
            $asset->getStream(),
            $attachment->filename,
            ['mimeType' => $asset->getMimeType(), 'fileSize' => $asset->size],
        );
    }

    /**
     * The ticket this request is entitled to see, or a 404.
     *
     * A **404 rather than a 403** for a bad reference, deliberately: telling somebody that a
     * reference exists but is not theirs is telling them that references can be guessed at.
     */
    private function authorize(): Ticket
    {
        $request = Craft::$app->getRequest();
        $plugin = Plugin::getInstance();

        $reference = (string)($request->getParam('csrRef') ?? $request->getBodyParam('csrRef') ?? '');

        if ($reference !== '') {
            $ticket = $plugin->tickets->getTicketByReference($reference);

            if ($ticket !== null && $this->referenceIsCurrent($ticket)) {
                return $ticket;
            }

            throw new NotFoundHttpException();
        }

        $userId = Craft::$app->getUser()->getId();
        $ticketId = (int)($request->getParam('ticketId') ?? 0);

        if ($userId === null || $ticketId === 0) {
            throw new NotFoundHttpException();
        }

        /** @var Ticket|null $ticket */
        $ticket = Ticket::find()->id($ticketId)->requesterId($userId)->isSpam(null)->one();

        if ($ticket === null) {
            throw new NotFoundHttpException();
        }

        return $ticket;
    }

    /**
     * Whether the link is still inside its lifetime.
     *
     * Zero days means never expiring, which is the default: a support link that dies is a customer
     * who has to start again, and the reference is only ever as sensitive as the ticket it opens.
     * Sites handling something more sensitive than that can set a window.
     */
    private function referenceIsCurrent(Ticket $ticket): bool
    {
        $days = Plugin::getInstance()->getSettings()->portalLinkDays;

        if ($days <= 0 || $ticket->submittedAt === null) {
            return true;
        }

        // The last reply, not the raising date: a conversation that is still going must not have
        // its own link expire underneath it.
        $from = $ticket->lastReplyAt ?? $ticket->submittedAt;

        return (time() - $from->getTimestamp()) <= $days * 86400;
    }

    /**
     * Render one of CSR's own front-end templates, with the site allowed to overrule it.
     *
     * Named `page()`, not `render()`: `yii\base\Controller::render()` is public, so a private
     * method of that name is a compile-time fatal the moment the class autoloads -- the same
     * family as the `Model::load()` and `Element::getStatus()` collisions, and just as far from
     * its call site. A site that wants its own markup copies the template into `templates/csr/`
     * and edits it there; see {@see Templates}.
     *
     * @param array<string, mixed> $variables
     */
    private function page(string $template, array $variables): Response
    {
        Craft::$app->getView()->registerAssetBundle(KbAsset::class);

        $plugin = Plugin::getInstance();
        $path = 'csr/_portal/' . $template;

        return $this->renderTemplate($path, $variables + [
            'settings' => $plugin->getSettings(),
            'kbName' => $plugin->getSettings()->kbName,
            'baseUri' => $plugin->articles->getBaseUri(),
        ], Templates::modeFor($path));
    }
}
