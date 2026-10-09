<?php

declare(strict_types=1);

namespace justinholtweb\csr\controllers;

use Craft;
use craft\web\Controller;
use craft\web\UploadedFile;
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
 * The support form: the only anonymous write path in the plugin.
 *
 * **Everything here assumes the request is hostile.** The category is looked up and then re-asked
 * whether it accepts tickets; the custom questions are enumerated from configuration and each one
 * pulls its own value out of the request, so a value posted under an unknown handle is never read;
 * the priority is only honoured if the site said visitors may choose one; the status is the desk's
 * default and never the request's; uploads are read from the form's own file input rather than
 * from `$_FILES` at large; and every spam refusal returns the identical message, because a form
 * that says *why* it refused you is telling a robot which check to work around.
 *
 * ## The suggestions come first
 *
 * `actionSuggest()` is the point of the whole plugin. Before the form will take a message, it
 * offers the articles that look like the answer — and records what happened, so somebody can find
 * out whether writing documentation is worth the afternoon.
 */
class SupportController extends Controller
{
    protected array|bool|int $allowAnonymous = true;

    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requireSiteRequest();

        if (!Plugin::getInstance()->getSettings()->supportEnabled) {
            throw new NotFoundHttpException();
        }

        return true;
    }

    public function actionForm(?Ticket $ticket = null): Response
    {
        $plugin = Plugin::getInstance();
        $settings = $plugin->getSettings();

        if (!$settings->allowGuestTickets && Craft::$app->getUser()->getIsGuest()) {
            throw new ForbiddenHttpException(Craft::t('csr', 'Please sign in to contact support.'));
        }

        $categoryId = (int)Craft::$app->getRequest()->getParam('categoryId') ?: null;
        $category = $plugin->categories->getCategoryById($categoryId);

        return $this->page('form', [
            'ticket' => $ticket,
            'categories' => $plugin->categories->getTree(true, true),
            'category' => $category,
            'formFields' => $plugin->forms->getFieldsFor($category),
            'stamp' => $plugin->spam->stamp(),
            'honeypot' => $settings->honeypotField,
            'priorities' => \justinholtweb\csr\enums\Priority::options(),
        ]);
    }

    /**
     * Likely answers for what somebody is part-way through typing.
     *
     * Recorded as a deflection *session* rather than as one row per keystroke: a deflection rate
     * is a ratio of people, and one visitor being shown six articles is one person deciding once.
     */
    public function actionSuggest(): Response
    {
        $this->requireAcceptsJson();

        $plugin = Plugin::getInstance();
        $request = Craft::$app->getRequest();

        $text = trim((string)$request->getParam('text', ''));
        $categoryId = (int)$request->getParam('categoryId') ?: null;

        $results = $plugin->deflection->suggest($text, $categoryId);

        if ($results !== []) {
            $plugin->deflection->record($text, $results, $categoryId);
        }

        return $this->asJson([
            'results' => array_map(static fn($result) => [
                'id' => $result->article->id,
                'title' => (string)$result->article->title,
                'url' => (string)$result->article->getUrl(),
                'snippet' => $result->getSnippet(160),
                'confident' => $result->getIsConfident(),
            ], $results),
        ]);
    }

    /** They opened a suggestion. Not yet a deflection — they may still write in. */
    public function actionSuggestionClicked(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $articleId = (int)Craft::$app->getRequest()->getRequiredBodyParam('articleId');
        Plugin::getInstance()->deflection->recordClick($articleId);

        return $this->asJson(['success' => true]);
    }

    /**
     * They said it answered the question.
     *
     * The one button that makes the deflection rate a measurement rather than an inference: "they
     * went away" and "they were helped" look identical from the server, so CSR asks.
     */
    public function actionDeflected(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $articleId = (int)Craft::$app->getRequest()->getBodyParam('articleId') ?: null;
        Plugin::getInstance()->deflection->recordDeflected($articleId);

        return $this->asJson(['success' => true]);
    }

    public function actionSubmit(): ?Response
    {
        $this->requirePostRequest();

        $plugin = Plugin::getInstance();
        $settings = $plugin->getSettings();
        $request = Craft::$app->getRequest();

        if (!$settings->allowGuestTickets && Craft::$app->getUser()->getIsGuest()) {
            throw new ForbiddenHttpException(Craft::t('csr', 'Please sign in to contact support.'));
        }

        $posted = $request->getBodyParams();
        $email = trim((string)$request->getBodyParam('email', ''));

        $refusal = $plugin->spam->check($posted, $email);

        if ($refusal !== null) {
            $plugin->spam->logRefusal($refusal, $email);

            // A refusal has to reach the visitor. Re-rendering the page unchanged just makes
            // somebody press the button again — and the second submission looks even more like a
            // robot than the first.
            return $this->fail($plugin->spam->refusalMessage());
        }

        $categoryId = $request->getBodyParam('categoryId');
        $category = $plugin->categories->getCategoryById($categoryId !== null && $categoryId !== '' ? (int)$categoryId : null);

        // The answers are collected from the *form's* definition, then validated against it — so
        // a required question cannot be skipped by not posting it.
        $answers = $plugin->forms->collect((array)$request->getBodyParam('answers', []), $category);
        $errors = $plugin->forms->validateAnswers($answers, $category);

        if ($errors !== []) {
            return $this->fail(reset($errors));
        }

        $ticket = $plugin->tickets->submit([
            'name' => $request->getBodyParam('name'),
            'email' => $email,
            'subject' => (string)$request->getBodyParam('subject', ''),
            'message' => (string)$request->getBodyParam('message', ''),
            'categoryId' => $categoryId,
            'priority' => $request->getBodyParam('priority'),
            'answers' => (array)$request->getBodyParam('answers', []),
            'source' => TicketSource::Web,
        ]);

        if ($ticket->hasErrors()) {
            if ($request->getAcceptsJson()) {
                return $this->asModelFailure($ticket, Craft::t('csr', 'Couldn’t send that.'), 'ticket');
            }

            Craft::$app->getUrlManager()->setRouteParams(['ticket' => $ticket]);

            return null;
        }

        $this->handleUploads($ticket);

        if ($request->getAcceptsJson()) {
            return $this->asJson([
                'success' => true,
                'number' => $ticket->number,
                'url' => $ticket->getPortalUrl(),
            ]);
        }

        Craft::$app->getSession()->setNotice(Craft::t('csr', 'Thanks — your ticket is number {number}.', [
            'number' => $ticket->number,
        ]));

        return $this->redirectToPostedUrl($ticket, (string)($ticket->getPortalUrl() ?: '/'));
    }

    /**
     * Move any uploaded files into the configured volume.
     *
     * Read from the form's own `attachments` input rather than from `$_FILES` at large, capped by
     * count and by size, and refused by *extension* against the site's list. A failure here does
     * not fail the ticket: somebody in trouble whose screenshot was too big should still have
     * reached support.
     */
    private function handleUploads(Ticket $ticket): void
    {
        $plugin = Plugin::getInstance();
        $settings = $plugin->getSettings();

        if (!$settings->attachmentsEnabled || $settings->attachmentVolumeUid === null) {
            return;
        }

        $files = UploadedFile::getInstancesByName('attachments');
        $allowed = $settings->getAllowedExtensions();
        $maxSize = $settings->getMaxFileSizeBytes();

        foreach (array_slice($files, 0, max(1, $settings->maxFilesPerReply)) as $file) {
            $extension = strtolower($file->getExtension());

            if ($file->getHasError() || !in_array($extension, $allowed, true) || $file->size > $maxSize) {
                continue;
            }

            $tempPath = $file->saveAsTempFile();

            if ($tempPath !== false) {
                $plugin->tickets->storeAttachment($ticket, $tempPath, $file->name);
            }
        }
    }

    /** Refuse, visibly, without saying which check did it. */
    private function fail(string $message): ?Response
    {
        $request = Craft::$app->getRequest();

        if ($request->getAcceptsJson()) {
            return $this->asFailure($message);
        }

        $ticket = new Ticket();
        $ticket->addError('message', $message);

        Craft::$app->getSession()->setError($message);
        Craft::$app->getUrlManager()->setRouteParams(['ticket' => $ticket]);

        return null;
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
        $path = 'csr/_support/' . $template;

        return $this->renderTemplate($path, $variables + [
            'settings' => $plugin->getSettings(),
            'kbName' => $plugin->getSettings()->kbName,
            'baseUri' => $plugin->articles->getBaseUri(),
            'allowsAttachments' => $plugin->getSettings()->attachmentsEnabled,
            'allowsCustomFields' => Edition::allowsCustomFormFields($plugin->isPro()),
        ], Templates::modeFor($path));
    }
}
