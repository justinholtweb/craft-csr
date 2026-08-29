<?php

declare(strict_types=1);

namespace justinholtweb\csr\services;

use Craft;
use craft\base\Component;
use craft\helpers\Html;
use craft\helpers\Json;
use justinholtweb\csr\elements\Article;
use justinholtweb\csr\models\Category;
use justinholtweb\csr\Plugin;

/**
 * The structured data a help centre earns.
 *
 * Built here rather than in a template, for the reason invalid structured data is always a silent
 * failure: nothing breaks, nothing warns, the rich result simply never appears and nobody finds
 * out for a year.
 *
 * Two shapes, and which one to use is a real decision:
 *
 * - **`Article`** for a help page. Straightforward, and what a documentation page is.
 * - **`FAQPage`** for a category of short question-shaped articles. It is the shape that earns an
 *   answer box, and it is also the one Google penalises when it is used on a page that is not
 *   really a list of questions — so CSR only emits it when the articles in a category genuinely
 *   look like questions, rather than because somebody switched it on.
 */
class Schema extends Component
{
    /** @return array<string, mixed> */
    public function forArticle(Article $article): array
    {
        $schema = [
            '@context' => 'https://schema.org',
            '@type' => 'Article',
            'headline' => (string)$article->title,
            'url' => (string)$article->getUrl(),
            'datePublished' => $article->dateCreated?->format(DATE_ATOM),
            'dateModified' => $article->dateUpdated?->format(DATE_ATOM),
            'publisher' => [
                '@type' => 'Organization',
                'name' => Craft::$app->getSites()->getPrimarySite()->getName(),
            ],
        ];

        if ($article->excerpt) {
            $schema['description'] = $article->excerpt;
        }

        $author = $article->authorId !== null
            ? Craft::$app->getUsers()->getUserById($article->authorId)
            : null;

        if ($author !== null) {
            $schema['author'] = ['@type' => 'Person', 'name' => $author->getFriendlyName()];
        }

        $category = $article->getCategory();

        if ($category !== null) {
            $schema['articleSection'] = $category->name;
        }

        return array_filter($schema, static fn($value) => $value !== null && $value !== '');
    }

    /**
     * A `FAQPage` for a category, or null when its articles are not questions.
     *
     * The test is deliberately conservative: most titles in the category have to end in a question
     * mark or start with one of the words a question starts with. Emitting `FAQPage` for a set of
     * reference pages is not a clever trick, it is a structured-data violation.
     *
     * @param Article[] $articles
     * @return array<string, mixed>|null
     */
    public function forFaq(Category $category, array $articles): ?array
    {
        if (count($articles) < 2) {
            return null;
        }

        $questions = array_filter($articles, static fn(Article $article) => self::looksLikeAQuestion((string)$article->title));

        if (count($questions) < count($articles) * 0.6) {
            return null;
        }

        $entities = [];

        foreach ($questions as $article) {
            $answer = trim($article->excerpt ?: $article->getPlainTextBody());

            if ($answer === '') {
                continue;
            }

            $entities[] = [
                '@type' => 'Question',
                'name' => (string)$article->title,
                'acceptedAnswer' => [
                    '@type' => 'Answer',
                    'text' => mb_substr($answer, 0, 1200),
                    'url' => (string)$article->getUrl(),
                ],
            ];
        }

        if ($entities === []) {
            return null;
        }

        return [
            '@context' => 'https://schema.org',
            '@type' => 'FAQPage',
            'name' => $category->name,
            'mainEntity' => $entities,
        ];
    }

    /**
     * Breadcrumbs for an article: the help centre, its category, the page.
     *
     * @return array<string, mixed>|null
     */
    public function forBreadcrumbs(Article $article): ?array
    {
        $base = Plugin::getInstance()->articles->getBaseUri((int)$article->siteId);

        if ($base === null) {
            return null;
        }

        $items = [[
            '@type' => 'ListItem',
            'position' => 1,
            'name' => Plugin::getInstance()->getSettings()->kbName,
            'item' => \craft\helpers\UrlHelper::siteUrl($base, siteId: $article->siteId),
        ]];

        $category = $article->getCategory();

        if ($category !== null) {
            $items[] = [
                '@type' => 'ListItem',
                'position' => 2,
                'name' => $category->name,
                'item' => $category->getUrl((int)$article->siteId),
            ];
        }

        $items[] = [
            '@type' => 'ListItem',
            'position' => count($items) + 1,
            'name' => (string)$article->title,
            'item' => (string)$article->getUrl(),
        ];

        return [
            '@context' => 'https://schema.org',
            '@type' => 'BreadcrumbList',
            'itemListElement' => $items,
        ];
    }

    /**
     * A `<script>` tag, ready to print.
     *
     * `JSON_UNESCAPED_SLASHES` is off deliberately: a `</script>` inside a JSON string ends the
     * element as far as the HTML parser is concerned, and escaping the slash is what stops an
     * article title from being able to break out of the tag.
     *
     * @param array<string, mixed>|null $schema
     */
    public function render(?array $schema): string
    {
        if ($schema === null || $schema === []) {
            return '';
        }

        return Html::tag(
            'script',
            Json::encode($schema, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
            ['type' => 'application/ld+json'],
        );
    }

    private static function looksLikeAQuestion(string $title): bool
    {
        if (str_ends_with(trim($title), '?')) {
            return true;
        }

        $first = strtolower(strtok(trim($title), ' ') ?: '');

        return in_array($first, ['how', 'what', 'why', 'when', 'where', 'which', 'who', 'can', 'do', 'does', 'is', 'are'], true);
    }
}
