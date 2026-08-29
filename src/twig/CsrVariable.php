<?php

declare(strict_types=1);

namespace justinholtweb\csr\twig;

use Craft;
use craft\elements\db\ElementQueryInterface;
use justinholtweb\csr\elements\Article;
use justinholtweb\csr\elements\Ticket;
use justinholtweb\csr\models\Category;
use justinholtweb\csr\models\SearchResult;
use justinholtweb\csr\Plugin;

/**
 * `craft.csr` — everything a site's own templates need.
 *
 * The rule this follows: **a template asking for articles gets the visibility gate for free.**
 * `craft.csr.articles()` is pre-filtered to what the current visitor may read, because the
 * commonest way a knowledge base leaks a restricted page is a listing somebody wrote without
 * knowing there was anything to filter. A template that genuinely wants everything can still say
 * `craft.csr.allArticles()`, which is a longer name on purpose.
 */
class CsrVariable
{
    // The knowledge base
    // -------------------------------------------------------------------------

    /** Articles the current visitor may read. */
    public function articles(array $criteria = []): ElementQueryInterface
    {
        $query = Article::find()->forCurrentUser();

        return $criteria !== [] ? Craft::configure($query, $criteria) : $query;
    }

    /** Every article, gate and all. For control-panel-side templates and exports. */
    public function allArticles(array $criteria = []): ElementQueryInterface
    {
        $query = Article::find();

        return $criteria !== [] ? Craft::configure($query, $criteria) : $query;
    }

    public function article(mixed $id, ?int $siteId = null): ?Article
    {
        /** @var Article|null $article */
        $article = is_numeric($id)
            ? Article::find()->id((int)$id)->siteId($siteId)->forCurrentUser()->one()
            : Article::find()->slug((string)$id)->siteId($siteId)->forCurrentUser()->one();

        return $article;
    }

    /** @return Category[] Top-level categories with their children attached. */
    public function categories(bool $ticketsOnly = false): array
    {
        return Plugin::getInstance()->categories->getTree(true, $ticketsOnly);
    }

    public function category(mixed $handleOrId): ?Category
    {
        $categories = Plugin::getInstance()->categories;

        return is_numeric($handleOrId)
            ? $categories->getCategoryById((int)$handleOrId)
            : $categories->getCategoryByHandle((string)$handleOrId);
    }

    /** @return Article[] */
    public function popular(?int $limit = null, ?int $categoryId = null): array
    {
        return Plugin::getInstance()->articles->getPopular(null, $limit, $categoryId);
    }

    /** @return Article[] */
    public function featured(?int $limit = null): array
    {
        return Plugin::getInstance()->articles->getFeatured(null, $limit);
    }

    /**
     * Search the knowledge base.
     *
     * Logged by default, because an unlogged search is a question the site was asked and did not
     * write down — and the failed-search report is the reason this plugin exists.
     *
     * @return SearchResult[]
     */
    public function search(string $query, array $options = []): array
    {
        return Plugin::getInstance()->search->find($query, $options + ['log' => true]);
    }

    /**
     * Likely answers for something part-typed, without logging it as a search.
     *
     * @return SearchResult[]
     */
    public function suggest(string $text, ?int $categoryId = null): array
    {
        return Plugin::getInstance()->deflection->suggest($text, $categoryId);
    }

    /** Where the knowledge base lives on this site. */
    public function kbUrl(?string $path = null): string
    {
        $base = Plugin::getInstance()->articles->getBaseUri() ?? '';

        return \craft\helpers\UrlHelper::siteUrl(trim($base . '/' . ltrim((string)$path, '/'), '/'));
    }

    public function supportUrl(): string
    {
        return \craft\helpers\UrlHelper::siteUrl(Plugin::getInstance()->getSettings()->supportPath);
    }

    /** What this visitor already said about an article, so the control can show its own state. */
    public function myVote(Article $article): ?bool
    {
        return Plugin::getInstance()->ratings->getExistingVote($article);
    }

    // The desk
    // -------------------------------------------------------------------------

    /**
     * Tickets, unfiltered.
     *
     * No visitor gate here on purpose, and the reason is worth stating: there is no safe way to
     * guess which tickets a front-end template means. Use {@see self::myTickets()} for the logged-in
     * customer's own, or {@see self::ticket()} with a reference for a guest's — anything else is
     * for control-panel-side templates.
     */
    public function tickets(array $criteria = []): ElementQueryInterface
    {
        $query = Ticket::find();

        return $criteria !== [] ? Craft::configure($query, $criteria) : $query;
    }

    /** The logged-in customer's own tickets. Returns nothing at all for a guest. */
    public function myTickets(array $criteria = []): ElementQueryInterface
    {
        $userId = Craft::$app->getUser()->getId();

        $query = Ticket::find()->requesterId($userId ?? 0);

        // A guest has no tickets, and the safe way to say that is a query that matches nothing —
        // not one with the requester filter quietly missing.
        if ($userId === null) {
            $query->id(0);
        }

        return $criteria !== [] ? Craft::configure($query, $criteria) : $query;
    }

    /** A ticket by its reference — the only way a guest reaches their own. */
    public function ticket(string $reference): ?Ticket
    {
        return Plugin::getInstance()->tickets->getTicketByReference($reference);
    }

    /** @return \justinholtweb\csr\models\FormField[] The site's own questions, for this subject. */
    public function formFields(?Category $category = null): array
    {
        return Plugin::getInstance()->forms->getFieldsFor($category);
    }

    /** The signed timestamp the support form needs, so the too-fast check can run. */
    public function stamp(): string
    {
        return Plugin::getInstance()->spam->stamp();
    }

    public function honeypotField(): string
    {
        return Plugin::getInstance()->getSettings()->honeypotField;
    }

    /** @return array<string, string> */
    public function priorities(): array
    {
        return \justinholtweb\csr\enums\Priority::options();
    }

    public function settings(): \justinholtweb\csr\models\Settings
    {
        return Plugin::getInstance()->getSettings();
    }

    public function isPro(): bool
    {
        return Plugin::getInstance()->isPro();
    }

    // Structured data
    // -------------------------------------------------------------------------

    /** A ready-to-print `<script type="application/ld+json">` for an article. */
    public function articleSchema(Article $article): \Twig\Markup
    {
        $schema = Plugin::getInstance()->schema;

        return \craft\helpers\Template::raw(
            $schema->render($schema->forArticle($article)) . $schema->render($schema->forBreadcrumbs($article)),
        );
    }

    /** A `FAQPage` for a category, or nothing when its articles are not questions. */
    public function faqSchema(Category $category, array $articles): \Twig\Markup
    {
        $schema = Plugin::getInstance()->schema;

        return \craft\helpers\Template::raw($schema->render($schema->forFaq($category, $articles)));
    }
}
