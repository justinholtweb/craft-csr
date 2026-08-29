<?php

declare(strict_types=1);

namespace justinholtweb\csr\controllers;

use Craft;
use craft\helpers\UrlHelper;
use craft\web\Controller;
use justinholtweb\csr\elements\Article;
use justinholtweb\csr\enums\Visibility;
use justinholtweb\csr\models\Edition;
use justinholtweb\csr\Plugin;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * The knowledge base's control panel screens.
 *
 * A bespoke edit screen rather than Craft's generic element editor, because half of what makes an
 * article an article has no generic control: the category it is filed under, who may read it, the
 * customer-language phrases it should be found by, and the pages to read next.
 */
class ArticlesController extends Controller
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
        return $this->renderTemplate('csr/articles/_index', [
            'canCreate' => Craft::$app->getUser()->checkPermission(Plugin::PERMISSION_MANAGE_ARTICLES),
        ]);
    }

    public function actionEdit(?int $articleId = null, ?Article $article = null): Response
    {
        $plugin = Plugin::getInstance();
        $siteId = (int)(Craft::$app->getRequest()->getQueryParam('siteId')
            ?: Craft::$app->getSites()->getCurrentSite()->id);

        if ($article === null) {
            if ($articleId !== null) {
                /** @var Article|null $article */
                $article = Article::find()->id($articleId)->siteId($siteId)->status(null)->one();

                if ($article === null) {
                    throw new NotFoundHttpException('Article not found.');
                }
            } else {
                $this->requirePermission(Plugin::PERMISSION_MANAGE_ARTICLES);

                $article = new Article();
                $article->siteId = $siteId;
                $article->enabled = true;
                $article->categoryId = (int)Craft::$app->getRequest()->getQueryParam('categoryId') ?: null;
            }
        }

        return $this->renderTemplate('csr/articles/_edit', [
            'article' => $article,
            'isNew' => $article->id === null,
            'categoryOptions' => $plugin->categories->getOptions(),
            'visibilityOptions' => Visibility::options(),
            'userGroupOptions' => $this->userGroupOptions(),
            'canRestrict' => Edition::allowsRestrictedArticles($plugin->isPro()),
            'relatedArticles' => $article->id !== null ? $plugin->articles->getExplicitRelated($article) : [],
            'siteSettings' => $plugin->articles->getSiteSettings($siteId),
            'title' => $article->id !== null ? (string)$article->title : Craft::t('csr', 'New article'),
        ]);
    }

    public function actionSave(): ?Response
    {
        $this->requirePostRequest();
        $this->requirePermission(Plugin::PERMISSION_MANAGE_ARTICLES);

        $request = Craft::$app->getRequest();
        $articleId = $request->getBodyParam('articleId');
        $siteId = (int)$request->getBodyParam('siteId') ?: Craft::$app->getSites()->getCurrentSite()->id;

        if ($articleId) {
            /** @var Article|null $article */
            $article = Article::find()->id((int)$articleId)->siteId($siteId)->status(null)->one();

            if ($article === null) {
                throw new NotFoundHttpException('Article not found.');
            }
        } else {
            $article = new Article();
            $article->siteId = $siteId;
        }

        $article->title = $request->getBodyParam('title');
        $article->slug = $request->getBodyParam('slug') ?: $article->slug;
        $article->enabled = (bool)$request->getBodyParam('enabled', true);
        $article->categoryId = ($categoryId = $request->getBodyParam('categoryId')) !== '' && $categoryId !== null
            ? (int)$categoryId
            : null;
        $article->excerpt = $request->getBodyParam('excerpt') ?: null;
        $article->keywords = $request->getBodyParam('keywords') ?: null;
        $article->featured = (bool)$request->getBodyParam('featured');
        $article->allowRatings = (bool)$request->getBodyParam('allowRatings', true);

        if (Edition::allowsRestrictedArticles(Plugin::getInstance()->isPro())) {
            $article->visibility = (string)$request->getBodyParam('visibility', Visibility::Public->value);
            $article->setAllowedGroupIds($request->getBodyParam('allowedGroupIds') ?? []);
        }

        // Read and tested for null rather than defaulted to `[]`: `getBodyParam('relatedIds', [])`
        // turns an *absent* key into "clear them all", so any partial save — an integration, a
        // screen that does not render this control — would silently unfile every related article.
        $relatedIds = $request->getBodyParam('relatedIds');
        $article->setRelatedIds($relatedIds !== null ? (array)$relatedIds : null);

        $article->setFieldValuesFromRequest('fields');

        if (!Craft::$app->getElements()->saveElement($article)) {
            if ($request->getAcceptsJson()) {
                return $this->asModelFailure($article, Craft::t('csr', 'Couldn’t save the article.'), 'article');
            }

            Craft::$app->getSession()->setError(Craft::t('csr', 'Couldn’t save the article.'));
            Craft::$app->getUrlManager()->setRouteParams(['article' => $article]);

            return null;
        }

        if ($request->getAcceptsJson()) {
            return $this->asModelSuccess($article, Craft::t('csr', 'Article saved.'), 'article');
        }

        return $this->redirectToPostedUrl($article, UrlHelper::cpUrl('csr/articles'));
    }

    public function actionDelete(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission(Plugin::PERMISSION_DELETE_ARTICLES);

        $articleId = (int)Craft::$app->getRequest()->getRequiredBodyParam('articleId');
        $article = Article::find()->id($articleId)->siteId('*')->unique()->status(null)->one();

        if ($article === null) {
            throw new NotFoundHttpException('Article not found.');
        }

        Craft::$app->getElements()->deleteElement($article);

        return $this->asSuccess(Craft::t('csr', 'Article deleted.'), redirect: UrlHelper::cpUrl('csr/articles'));
    }

    /**
     * Reorder the articles in a category.
     *
     * Posted from JavaScript rather than from a nested form: a `<form>` inside a control panel
     * page corrupts the page's own form — the parser drops the inner tag and keeps its children,
     * so a second `action` input ends up next to the first and Craft takes the last one.
     */
    public function actionReorder(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();
        $this->requirePermission(Plugin::PERMISSION_MANAGE_ARTICLES);

        $ids = Craft::$app->getRequest()->getRequiredBodyParam('ids');
        Plugin::getInstance()->articles->reorder(array_map('intval', (array)$ids));

        return $this->asSuccess(Craft::t('csr', 'Articles reordered.'));
    }

    /** @return array<int, array{label: string, value: string}> */
    private function userGroupOptions(): array
    {
        $options = [];

        foreach (Craft::$app->getUserGroups()->getAllGroups() as $group) {
            $options[] = ['label' => $group->name, 'value' => (string)$group->id];
        }

        return $options;
    }
}
