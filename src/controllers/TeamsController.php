<?php

declare(strict_types=1);

namespace justinholtweb\csr\controllers;

use Craft;
use craft\helpers\StringHelper;
use craft\helpers\UrlHelper;
use craft\web\Controller;
use justinholtweb\csr\models\Edition;
use justinholtweb\csr\models\Team;
use justinholtweb\csr\Plugin;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/** Groups of agents. Pro. */
class TeamsController extends Controller
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requireCpRequest();
        $this->requireAdmin();

        if (!Edition::allowsTeams(Plugin::getInstance()->isPro())) {
            throw new ForbiddenHttpException('Teams are a Pro feature.');
        }

        return true;
    }

    public function actionIndex(): Response
    {
        return $this->renderTemplate('csr/settings/teams/_index', [
            'teams' => Plugin::getInstance()->teams->getAllTeams(),
        ]);
    }

    public function actionEdit(?int $teamId = null, ?Team $team = null): Response
    {
        if ($team === null) {
            if ($teamId !== null) {
                $team = Plugin::getInstance()->teams->getTeamById($teamId);

                if ($team === null) {
                    throw new NotFoundHttpException('Team not found.');
                }
            } else {
                $team = new Team();
            }
        }

        return $this->renderTemplate('csr/settings/teams/_edit', [
            'team' => $team,
            'isNew' => $team->id === null,
            'members' => $team->getMembers(),
            'title' => $team->id !== null ? $team->name : Craft::t('csr', 'New team'),
        ]);
    }

    public function actionSave(): ?Response
    {
        $this->requirePostRequest();

        $request = Craft::$app->getRequest();
        $plugin = Plugin::getInstance();
        $teamId = $request->getBodyParam('teamId');

        $team = $teamId ? $plugin->teams->getTeamById((int)$teamId) : new Team();

        if ($team === null) {
            throw new NotFoundHttpException('Team not found.');
        }

        $team->name = (string)$request->getBodyParam('name');
        $team->handle = (string)($request->getBodyParam('handle') ?: StringHelper::toCamelCase($team->name));
        $team->description = $request->getBodyParam('description') ?: null;
        $team->color = ltrim((string)$request->getBodyParam('color', 'blue'), '#');
        $team->email = $request->getBodyParam('email') ?: null;

        // Craft's element select renders two inputs for the same value — an empty placeholder
        // named `memberIds[]` and one inside each chip carrying the real id — so the posted array
        // always contains an empty string, and a missing key means the control was not rendered.
        $memberIds = $request->getBodyParam('memberIds');
        $team->setMemberIds($memberIds !== null
            ? array_values(array_filter((array)$memberIds, static fn($id) => $id !== '' && $id !== null))
            : null);

        if (!$plugin->teams->saveTeam($team)) {
            Craft::$app->getSession()->setError(Craft::t('csr', 'Couldn’t save the team.'));
            Craft::$app->getUrlManager()->setRouteParams(['team' => $team]);

            return null;
        }

        return $this->asSuccess(
            Craft::t('csr', 'Team saved.'),
            ['team' => $team],
            UrlHelper::cpUrl('csr/settings/teams'),
        );
    }

    public function actionDelete(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $id = (int)Craft::$app->getRequest()->getRequiredBodyParam('id');
        Plugin::getInstance()->teams->deleteTeamById($id);

        return $this->asSuccess(Craft::t('csr', 'Team deleted.'));
    }
}
