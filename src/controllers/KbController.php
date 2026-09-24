<?php

declare(strict_types=1);

namespace justinholtweb\csr\controllers;

use Craft;
use craft\web\Controller;
use craft\web\View;
use justinholtweb\csr\elements\Article;
use justinholtweb\csr\helpers\Templates;
use justinholtweb\csr\Plugin;
use justinholtweb\csr\web\assets\kb\KbAsset;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * The public knowledge base.
 *
 * Everything here is anonymous, and everything here goes through
 * {@see \justinholtweb\csr\elements\db\ArticleQuery::forUser()} — the visibility gate is applied in
 * the *query*, so a restricted article cannot reach a listing, a search result or a snippet. The
 * commonest way a knowledge base leaks is not its pages; it is its search index quoting the first
 * sentence of a page nobody was supposed to see.
 *
 * A site that wants its own markup names a template in the site settings and CSR gets out of the
 * way. These templates exist so the plugin works the day it is installed.
 */
class KbController extends Controller
{
    protected array|bool|int $allowAnonymous = true;

    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requireSiteRequest();

        if (!Plugin::getInstance()->getSettings()->kbEnabled) {
            throw new NotFoundHttpException();
        }

        return true;
    }

    public function actionIndex(): Response
    {
        $plugin = Plugin::getInstance();
        $siteId = (int)Craft::$app->getSites()->getCurrentSite()->id;

        return $this->page('index', [
            'categories' => $plugin->categories->getTree(),
            'featured' => $plugin->articles->getFeatured($siteId),
            'popular' => $plugin->articles->getPopular($siteId, 6),
        ]);
    }

    public function actionCategory(string $handle): Response
    {
        $plugin = Plugin::getInstance();
        $category = $plugin->categories->getCategoryByHandle($handle);

        if ($category === null || !$category->enabled) {
            throw new NotFoundHttpException();
        }

        $articles = Article::find()
            ->siteId(Craft::$app->getSites()->getCurrentSite()->id)
            ->categoryId($plugin->categories->getIdsWithDescendants((int)$category->id))
            ->forCurrentUser()
            ->limit($plugin->getSettings()->articlesPerPage)
            ->all();

        return $this->page('category', [
            'category' => $category,
            'articles' => $articles,
            'children' => $category->getChildren(),
        ]);
    }

    /**
     * The article page CSR falls back to when a site has not written its own template.
     *
     * Reached through the element's `route()`, so the URL is the article's own URI — this is not a
     * second address for the same page.
     */
    public function actionArticle(?Article $article = null): Response
    {
        if ($article === null) {
            throw new NotFoundHttpException();
        }

        // Checked again here rather than trusted from the route: an element's URI is resolved by
        // Craft before CSR sees the request, and Craft has no opinion about who may read it.
        if (!$article->isVisibleTo(Craft::$app->getUser()->getIdentity())) {
            throw new ForbiddenHttpException(Craft::t('csr', 'You don’t have access to that article.'));
        }

        $plugin = Plugin::getInstance();
        $plugin->articles->recordView($article);

        // A visitor arriving from the search results page: attach the click to the search that
        // sent them, so "they searched and opened nothing" stays an answerable question.
        $query = (string)Craft::$app->getRequest()->getQueryParam('csrq', '');

        if ($query !== '') {
            $plugin->search->recordClick($query, (int)$article->id);
        }

        $variables = [
            'article' => $article,
            'category' => $article->getCategory(),
            'related' => $article->getRelatedArticles(),
            'existingVote' => $plugin->ratings->getExistingVote($article),
        ];

        // The site named its own template on the Sites screen: render that, as the site's, with
        // the same variables. Everything above has already run, which is the point.
        $siteTemplate = $article->getSiteSettings()?->template;

        if ($siteTemplate) {
            return $this->renderTemplate($siteTemplate, $variables + [
                'settings' => $plugin->getSettings(),
                'kbName' => $plugin->getSettings()->kbName,
                'baseUri' => $plugin->articles->getBaseUri(),
            ], View::TEMPLATE_MODE_SITE);
        }

        return $this->page('article', $variables);
    }

    public function actionSearch(): Response
    {
        $plugin = Plugin::getInstance();
        $request = Craft::$app->getRequest();
        $query = trim((string)$request->getParam('q', ''));

        $results = $query !== ''
            ? $plugin->search->find($query, ['log' => true, 'source' => 'kb'])
            : [];

        if ($request->getAcceptsJson()) {
            return $this->asJson([
                'query' => $query,
                'results' => array_map(static fn($result) => [
                    'id' => $result->article->id,
                    'title' => (string)$result->article->title,
                    'url' => (string)$result->article->getUrl(),
                    'snippet' => $result->getSnippet(140),
                    'category' => (string)$result->article->getCategory()?->name,
                ], $results),
            ]);
        }

        return $this->page('search', [
            'query' => $query,
            'results' => $results,
            'categories' => $plugin->categories->getTree(),
        ]);
    }

    /**
     * The live search dropdown.
     *
     * Deliberately not logged: somebody typing "ref" on their way to "refund" has not asked a
     * question yet, and logging every keystroke would fill the failed-search report with prefixes
     * of real searches and bury the ones that matter.
     */
    public function actionSuggest(): Response
    {
        $this->requireAcceptsJson();

        $plugin = Plugin::getInstance();
        $text = trim((string)Craft::$app->getRequest()->getParam('q', ''));

        if (mb_strlen($text) < $plugin->getSettings()->searchMinLength) {
            return $this->asJson(['results' => []]);
        }

        $results = $plugin->search->suggest($text);

        return $this->asJson([
            'results' => array_map(static fn($result) => [
                'title' => (string)$result->article->title,
                'url' => (string)$result->article->getUrl(),
                'category' => (string)$result->article->getCategory()?->name,
            ], $results),
        ]);
    }

    /** "Was this helpful?" */
    public function actionRate(): Response
    {
        $this->requirePostRequest();

        $plugin = Plugin::getInstance();
        $request = Craft::$app->getRequest();

        $articleId = (int)$request->getRequiredBodyParam('articleId');

        /** @var Article|null $article */
        $article = Article::find()->id($articleId)->forCurrentUser()->one();

        if ($article === null) {
            throw new NotFoundHttpException();
        }

        $helpful = (bool)$request->getRequiredBodyParam('helpful');
        $comment = $plugin->getSettings()->askWhyOnUnhelpful ? $request->getBodyParam('comment') : null;

        $rating = $plugin->ratings->rate($article, $helpful, $comment !== null ? (string)$comment : null);

        if ($request->getAcceptsJson()) {
            return $this->asJson([
                'success' => $rating !== null,
                'helpful' => $article->helpfulCount,
                'unhelpful' => $article->unhelpfulCount,
                // Only asked after a "no", which is when the answer is worth having.
                'askWhy' => !$helpful && $plugin->getSettings()->askWhyOnUnhelpful && $comment === null,
            ]);
        }

        return $this->redirectToPostedUrl($article);
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
        $path = 'csr/_kb/' . $template;

        return $this->renderTemplate($path, $variables + [
            'settings' => $plugin->getSettings(),
            'kbName' => $plugin->getSettings()->kbName,
            'baseUri' => $plugin->articles->getBaseUri(),
        ], Templates::modeFor($path));
    }
}
