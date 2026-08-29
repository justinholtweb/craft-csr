<?php

declare(strict_types=1);

namespace justinholtweb\csr\controllers;

use Craft;
use craft\helpers\StringHelper;
use craft\helpers\UrlHelper;
use craft\web\Controller;
use justinholtweb\csr\enums\StatusKind;
use justinholtweb\csr\models\Edition;
use justinholtweb\csr\models\Status;
use justinholtweb\csr\Plugin;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/** The states a ticket can be in. Project config, so admin-only and gated on allowAdminChanges. */
class StatusesController extends Controller
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requireCpRequest();
        $this->requireAdmin();

        return true;
    }

    public function actionIndex(): Response
    {
        $plugin = Plugin::getInstance();

        return $this->renderTemplate('csr/settings/statuses/_index', [
            'statuses' => $plugin->statuses->getAllStatuses(),
            'canCreate' => $plugin->statuses->canCreateStatus(),
            'maxStatuses' => Edition::maxStatuses($plugin->isPro()),
        ]);
    }

    public function actionEdit(?int $statusId = null, ?Status $status = null): Response
    {
        if ($status === null) {
            if ($statusId !== null) {
                $status = Plugin::getInstance()->statuses->getStatusById($statusId);

                if ($status === null) {
                    throw new NotFoundHttpException('Status not found.');
                }
            } else {
                $status = new Status();
            }
        }

        return $this->renderTemplate('csr/settings/statuses/_edit', [
            'status' => $status,
            'isNew' => $status->id === null,
            'kindOptions' => StatusKind::options(),
            'title' => $status->id !== null ? $status->name : Craft::t('csr', 'New status'),
        ]);
    }

    public function actionSave(): ?Response
    {
        $this->requirePostRequest();

        $request = Craft::$app->getRequest();
        $plugin = Plugin::getInstance();
        $statusId = $request->getBodyParam('statusId');

        $status = $statusId ? $plugin->statuses->getStatusById((int)$statusId) : new Status();

        if ($status === null) {
            throw new NotFoundHttpException('Status not found.');
        }

        $status->name = (string)$request->getBodyParam('name');
        $status->handle = (string)($request->getBodyParam('handle') ?: StringHelper::toCamelCase($status->name));
        $status->kind = (string)$request->getBodyParam('kind', StatusKind::Open->value);
        $status->color = ltrim((string)$request->getBodyParam('color', 'gray'), '#');
        $status->description = $request->getBodyParam('description') ?: null;
        $status->isDefault = (bool)$request->getBodyParam('isDefault');
        $status->notifyRequester = (bool)$request->getBodyParam('notifyRequester');
        $status->emailSubject = $request->getBodyParam('emailSubject') ?: null;
        $status->emailBody = $request->getBodyParam('emailBody') ?: null;

        if (!$plugin->statuses->saveStatus($status)) {
            Craft::$app->getSession()->setError(Craft::t('csr', 'Couldn’t save the status.'));
            Craft::$app->getUrlManager()->setRouteParams(['status' => $status]);

            return null;
        }

        return $this->asSuccess(
            Craft::t('csr', 'Status saved.'),
            ['status' => $status],
            UrlHelper::cpUrl('csr/settings/statuses'),
        );
    }

    public function actionDelete(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $id = (int)Craft::$app->getRequest()->getRequiredBodyParam('id');
        $plugin = Plugin::getInstance();
        $status = $plugin->statuses->getStatusById($id);

        if ($status === null || !$plugin->statuses->deleteStatus($status)) {
            return $this->asFailure($status?->getFirstError('name') ?? Craft::t('csr', 'Couldn’t delete that status.'));
        }

        return $this->asSuccess(Craft::t('csr', 'Status deleted.'));
    }

    public function actionReorder(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $uids = Craft::$app->getRequest()->getRequiredBodyParam('ids');
        Plugin::getInstance()->statuses->reorderStatuses((array)$uids);

        return $this->asSuccess(Craft::t('csr', 'Statuses reordered.'));
    }
}
