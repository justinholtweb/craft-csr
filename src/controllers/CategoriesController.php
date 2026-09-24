<?php

declare(strict_types=1);

namespace justinholtweb\csr\controllers;

use Craft;
use craft\helpers\StringHelper;
use craft\helpers\UrlHelper;
use craft\web\Controller;
use justinholtweb\csr\models\Category;
use justinholtweb\csr\models\Edition;
use justinholtweb\csr\Plugin;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/** The subjects articles are filed under and tickets are raised about. */
class CategoriesController extends Controller
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requireCpRequest();
        $this->requirePermission(Plugin::PERMISSION_VIEW_ARTICLES);

        return true;
    }

    public function actionIndex(): Response
    {
        return $this->renderTemplate('csr/categories/_index', [
            'categories' => Plugin::getInstance()->categories->getAllWithCounts(),
            'tree' => Plugin::getInstance()->categories->getTree(false),
            'canManage' => Craft::$app->getUser()->checkPermission(Plugin::PERMISSION_MANAGE_CATEGORIES),
        ]);
    }

    public function actionEdit(?int $categoryId = null, ?Category $category = null): Response
    {
        $plugin = Plugin::getInstance();

        if ($category === null) {
            if ($categoryId !== null) {
                $category = $plugin->categories->getCategoryById($categoryId);

                if ($category === null) {
                    throw new NotFoundHttpException('Category not found.');
                }
            } else {
                $this->requirePermission(Plugin::PERMISSION_MANAGE_CATEGORIES);
                $category = new Category();
            }
        }

        $parentOptions = ['' => Craft::t('csr', 'None — a top-level category')];

        foreach ($plugin->categories->getAllCategories() as $candidate) {
            // One level deep: a category that already has a parent cannot become one, and nothing
            // may be filed under itself.
            if ($candidate->id !== $category->id && $candidate->parentId === null) {
                $parentOptions[(string)$candidate->id] = $candidate->name;
            }
        }

        return $this->renderTemplate('csr/categories/_edit', [
            'category' => $category,
            'isNew' => $category->id === null,
            'parentOptions' => $parentOptions,
            'allowsTeams' => Edition::allowsTeams(Plugin::getInstance()->isPro()),
            'teamOptions' => Plugin::getInstance()->teams->getOptions(),
            'title' => $category->id !== null ? $category->name : Craft::t('csr', 'New category'),
        ]);
    }

    public function actionSave(): ?Response
    {
        $this->requirePostRequest();
        $this->requirePermission(Plugin::PERMISSION_MANAGE_CATEGORIES);

        $request = Craft::$app->getRequest();
        $plugin = Plugin::getInstance();
        $categoryId = $request->getBodyParam('categoryId');

        $category = $categoryId
            ? $plugin->categories->getCategoryById((int)$categoryId)
            : new Category();

        if ($category === null) {
            throw new NotFoundHttpException('Category not found.');
        }

        $category->name = (string)$request->getBodyParam('name');
        $category->handle = (string)($request->getBodyParam('handle') ?: StringHelper::toCamelCase($category->name));
        $category->description = $request->getBodyParam('description') ?: null;
        $category->icon = $request->getBodyParam('icon') ?: null;
        // Craft's colour field posts hex *without* the leading hash, so a value validated against
        // `/^#[0-9a-f]{6}$/` would be rejected for every colour anybody picked.
        $category->color = ltrim((string)$request->getBodyParam('color', 'blue'), '#');
        $category->enabled = (bool)$request->getBodyParam('enabled', true);
        $category->acceptsTickets = (bool)$request->getBodyParam('acceptsTickets', true);

        // Only read when routing is available, so saving a category on Lite leaves a team set on
        // Pro where it was rather than clearing it.
        if (Edition::allowsTeams($plugin->isPro())) {
            $category->teamId = ($teamId = $request->getBodyParam('teamId')) ? (int)$teamId : null;
        }
        $category->parentId = ($parentId = $request->getBodyParam('parentId')) ? (int)$parentId : null;

        if (!$plugin->categories->saveCategory($category)) {
            if ($request->getAcceptsJson()) {
                return $this->asModelFailure($category, Craft::t('csr', 'Couldn’t save the category.'), 'category');
            }

            Craft::$app->getSession()->setError(Craft::t('csr', 'Couldn’t save the category.'));
            Craft::$app->getUrlManager()->setRouteParams(['category' => $category]);

            return null;
        }

        return $this->asSuccess(
            Craft::t('csr', 'Category saved.'),
            ['category' => $category],
            UrlHelper::cpUrl('csr/categories'),
        );
    }

    public function actionDelete(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission(Plugin::PERMISSION_MANAGE_CATEGORIES);

        $id = (int)Craft::$app->getRequest()->getRequiredBodyParam('categoryId');
        Plugin::getInstance()->categories->deleteCategoryById($id);

        return $this->asSuccess(
            Craft::t('csr', 'Category deleted. Nothing filed under it was.'),
            redirect: UrlHelper::cpUrl('csr/categories'),
        );
    }

    public function actionReorder(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();
        $this->requirePermission(Plugin::PERMISSION_MANAGE_CATEGORIES);

        $ids = Craft::$app->getRequest()->getRequiredBodyParam('ids');
        Plugin::getInstance()->categories->reorder(array_map('intval', (array)$ids));

        return $this->asSuccess(Craft::t('csr', 'Categories reordered.'));
    }
}
