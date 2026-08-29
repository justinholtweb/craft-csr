<?php

declare(strict_types=1);

namespace justinholtweb\csr\controllers;

use Craft;
use craft\helpers\UrlHelper;
use craft\web\Controller;
use justinholtweb\csr\elements\Ticket;
use justinholtweb\csr\models\CannedReply;
use justinholtweb\csr\models\Edition;
use justinholtweb\csr\Plugin;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * The library of replies.
 *
 * Not admin-only, and not project config: agents write these, and the whole value is that the
 * person who is best at explaining refunds can improve the refund explanation on a Tuesday
 * afternoon without asking anybody to deploy.
 */
class CannedRepliesController extends Controller
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requireCpRequest();
        $this->requirePermission(Plugin::PERMISSION_MANAGE_CANNED_REPLIES);

        if (!Edition::allowsCannedReplies(Plugin::getInstance()->isPro())) {
            throw new ForbiddenHttpException('Canned replies are a Pro feature.');
        }

        return true;
    }

    public function actionIndex(): Response
    {
        return $this->renderTemplate('csr/canned-replies/_index', [
            'replies' => Plugin::getInstance()->cannedReplies->getAllReplies(),
        ]);
    }

    public function actionEdit(?int $replyId = null, ?CannedReply $cannedReply = null): Response
    {
        $plugin = Plugin::getInstance();

        if ($cannedReply === null) {
            if ($replyId !== null) {
                $cannedReply = $plugin->cannedReplies->getReplyById($replyId);

                if ($cannedReply === null) {
                    throw new NotFoundHttpException('Canned reply not found.');
                }
            } else {
                $cannedReply = new CannedReply();
            }
        }

        return $this->renderTemplate('csr/canned-replies/_edit', [
            'cannedReply' => $cannedReply,
            'isNew' => $cannedReply->id === null,
            'categoryOptions' => $plugin->categories->getOptions(),
            'teamOptions' => $plugin->teams->getOptions(),
            'placeholders' => CannedReply::placeholderNames(),
            'title' => $cannedReply->id !== null ? $cannedReply->name : Craft::t('csr', 'New canned reply'),
        ]);
    }

    public function actionSave(): ?Response
    {
        $this->requirePostRequest();

        $request = Craft::$app->getRequest();
        $plugin = Plugin::getInstance();
        $replyId = $request->getBodyParam('replyId');

        $cannedReply = $replyId ? $plugin->cannedReplies->getReplyById((int)$replyId) : new CannedReply();

        if ($cannedReply === null) {
            throw new NotFoundHttpException('Canned reply not found.');
        }

        $cannedReply->name = (string)$request->getBodyParam('name');
        $cannedReply->shortcut = $request->getBodyParam('shortcut') ?: null;
        $cannedReply->body = (string)$request->getBodyParam('body');
        $cannedReply->categoryId = ($categoryId = $request->getBodyParam('categoryId')) ? (int)$categoryId : null;
        $cannedReply->teamId = ($teamId = $request->getBodyParam('teamId')) ? (int)$teamId : null;
        $cannedReply->enabled = (bool)$request->getBodyParam('enabled', true);

        if (!$plugin->cannedReplies->saveReply($cannedReply)) {
            Craft::$app->getSession()->setError(Craft::t('csr', 'Couldn’t save the reply.'));
            Craft::$app->getUrlManager()->setRouteParams(['cannedReply' => $cannedReply]);

            return null;
        }

        return $this->asSuccess(
            Craft::t('csr', 'Reply saved.'),
            ['cannedReply' => $cannedReply],
            UrlHelper::cpUrl('csr/canned-replies'),
        );
    }

    public function actionDelete(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $id = (int)Craft::$app->getRequest()->getRequiredBodyParam('id');
        Plugin::getInstance()->cannedReplies->deleteReplyById($id);

        return $this->asSuccess(Craft::t('csr', 'Reply deleted.'));
    }

    /**
     * The reply with its placeholders filled in for a ticket.
     *
     * Rendered here rather than in the browser so an agent sees exactly what the customer will —
     * placeholders resolved against the real ticket, and an unknown one left visible rather than
     * silently blanked.
     */
    public function actionRender(): Response
    {
        $this->requireAcceptsJson();

        $request = Craft::$app->getRequest();
        $plugin = Plugin::getInstance();

        $cannedReply = $plugin->cannedReplies->getReplyById((int)$request->getRequiredParam('replyId'));

        /** @var Ticket|null $ticket */
        $ticket = Ticket::find()->id((int)$request->getRequiredParam('ticketId'))->isSpam(null)->one();

        if ($cannedReply === null || $ticket === null) {
            return $this->asFailure(Craft::t('csr', 'Couldn’t find that.'));
        }

        return $this->asJson([
            'body' => $cannedReply->render($ticket),
            'id' => $cannedReply->id,
        ]);
    }
}
