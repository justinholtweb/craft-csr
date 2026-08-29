<?php

declare(strict_types=1);

namespace justinholtweb\csr\controllers;

use Craft;
use craft\helpers\StringHelper;
use craft\helpers\UrlHelper;
use craft\web\Controller;
use justinholtweb\csr\enums\Priority;
use justinholtweb\csr\enums\TicketSource;
use justinholtweb\csr\models\Edition;
use justinholtweb\csr\models\SlaPolicy;
use justinholtweb\csr\Plugin;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/** Promises with a clock on them. Pro. */
class SlasController extends Controller
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requireCpRequest();
        $this->requireAdmin();

        if (!Edition::allowsSlas(Plugin::getInstance()->isPro())) {
            throw new ForbiddenHttpException('Service level agreements are a Pro feature.');
        }

        return true;
    }

    public function actionIndex(): Response
    {
        $plugin = Plugin::getInstance();

        return $this->renderTemplate('csr/settings/slas/_index', [
            'policies' => $plugin->slas->getAllPolicies(),
            'slaEnabled' => $plugin->getSettings()->slaEnabled,
        ]);
    }

    public function actionEdit(?int $policyId = null, ?SlaPolicy $policy = null): Response
    {
        $plugin = Plugin::getInstance();

        if ($policy === null) {
            if ($policyId !== null) {
                $policy = $plugin->slas->getPolicyById($policyId);

                if ($policy === null) {
                    throw new NotFoundHttpException('Policy not found.');
                }
            } else {
                $policy = new SlaPolicy();
            }
        }

        $teamOptions = [];

        foreach ($plugin->teams->getAllTeams() as $team) {
            $teamOptions[] = ['label' => $team->name, 'value' => (string)$team->uid];
        }

        $categoryOptions = [];

        foreach ($plugin->categories->getAllCategories() as $category) {
            $categoryOptions[] = ['label' => $category->name, 'value' => (string)$category->uid];
        }

        $sourceOptions = [];

        foreach (TicketSource::cases() as $source) {
            $sourceOptions[] = ['label' => $source->label(), 'value' => $source->value];
        }

        return $this->renderTemplate('csr/settings/slas/_edit', [
            'policy' => $policy,
            'isNew' => $policy->id === null,
            'priorityOptions' => Priority::options(),
            'teamOptions' => $teamOptions,
            'categoryOptions' => $categoryOptions,
            'sourceOptions' => $sourceOptions,
            'escalationOptions' => array_merge([['label' => Craft::t('csr', 'Leave it where it is'), 'value' => '']], $teamOptions),
            'title' => $policy->id !== null ? $policy->name : Craft::t('csr', 'New SLA policy'),
        ]);
    }

    public function actionSave(): ?Response
    {
        $this->requirePostRequest();

        $request = Craft::$app->getRequest();
        $plugin = Plugin::getInstance();
        $policyId = $request->getBodyParam('policyId');

        $policy = $policyId ? $plugin->slas->getPolicyById((int)$policyId) : new SlaPolicy();

        if ($policy === null) {
            throw new NotFoundHttpException('Policy not found.');
        }

        $policy->name = (string)$request->getBodyParam('name');
        $policy->handle = (string)($request->getBodyParam('handle') ?: StringHelper::toCamelCase($policy->name));
        $policy->description = $request->getBodyParam('description') ?: null;
        $policy->enabled = (bool)$request->getBodyParam('enabled', true);
        $policy->businessHoursOnly = (bool)$request->getBodyParam('businessHoursOnly');
        $policy->notifyOnBreach = (bool)$request->getBodyParam('notifyOnBreach');
        $policy->escalateToTeamUid = $request->getBodyParam('escalateToTeamUid') ?: null;

        // Craft's number inputs post an empty string when cleared, and these are nullable ints —
        // "no promise" and "a promise of zero minutes" are very different things.
        foreach (['firstResponseMinutes', 'resolutionMinutes', 'escalateAfterMinutes'] as $attribute) {
            $value = $request->getBodyParam($attribute);
            $policy->$attribute = ($value === null || $value === '') ? null : (int)$value;
        }

        $policy->setConditions([
            'priorities' => (array)($request->getBodyParam('conditions.priorities') ?? []),
            'categoryUids' => (array)($request->getBodyParam('conditions.categoryUids') ?? []),
            'teamUids' => (array)($request->getBodyParam('conditions.teamUids') ?? []),
            'sources' => (array)($request->getBodyParam('conditions.sources') ?? []),
        ]);

        if (!$plugin->slas->savePolicy($policy)) {
            Craft::$app->getSession()->setError(Craft::t('csr', 'Couldn’t save the policy.'));
            Craft::$app->getUrlManager()->setRouteParams(['policy' => $policy]);

            return null;
        }

        return $this->asSuccess(
            Craft::t('csr', 'Policy saved.'),
            ['policy' => $policy],
            UrlHelper::cpUrl('csr/settings/slas'),
        );
    }

    public function actionDelete(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $id = (int)Craft::$app->getRequest()->getRequiredBodyParam('id');
        Plugin::getInstance()->slas->deletePolicyById($id);

        return $this->asSuccess(Craft::t('csr', 'Policy deleted.'));
    }

    public function actionReorder(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $uids = Craft::$app->getRequest()->getRequiredBodyParam('ids');
        Plugin::getInstance()->slas->reorderPolicies((array)$uids);

        return $this->asSuccess(Craft::t('csr', 'Policies reordered.'));
    }

    /** "A four-hour target set now would be due at…", so opening hours can be sanity-checked. */
    public function actionPreview(): Response
    {
        $this->requireAcceptsJson();

        $request = Craft::$app->getRequest();
        $minutes = max(1, (int)$request->getRequiredParam('minutes'));
        $businessHoursOnly = (bool)$request->getParam('businessHoursOnly', true);

        $due = Plugin::getInstance()->slas->preview($minutes, $businessHoursOnly);

        return $this->asJson([
            'due' => Craft::$app->getFormatter()->asDatetime($due, 'long'),
        ]);
    }
}
