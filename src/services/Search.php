<?php

declare(strict_types=1);

namespace justinholtweb\csr\services;

use Craft;
use craft\base\Component;
use craft\helpers\StringHelper;
use DateTime;
use justinholtweb\csr\elements\Article;
use justinholtweb\csr\helpers\Visitor;
use justinholtweb\csr\models\SearchResult;
use justinholtweb\csr\Plugin;
use justinholtweb\csr\records\SearchRecord;

/**
 * The one implementation of "find me the article about this".
 *
 * The knowledge base search box, the live dropdown, the deflection panel on the support form and
 * the "related articles" fallback all come through here. That is not tidiness — it is the only way
 * the failed-search report means anything. If the deflection panel used a different matcher from
 * the search page, "nothing found" in one would not be "nothing found" in the other, and the list
 * of things the knowledge base cannot answer would be measuring two different knowledge bases.
 *
 * ## Why not Craft's search index
 *
 * It is used, as a *second* pass, for the article body. It is not used as the first pass, because
 * a knowledge base that returns nothing until somebody runs `resave/csr-articles` is indistinguishable
 * from an empty one — and the failed-search report would then fill up with searches that did not
 * really fail, which is the worst possible failure mode for the feature this plugin exists for.
 *
 * ## Scoring
 *
 * Weighted by *where* a term matched, because in a help centre the location of a match is most of
 * the signal:
 *
 * - A title match is nearly always the right article.
 * - A **keywords** match is the next best thing, and often better: those are the phrases an author
 *   wrote down precisely because customers use them and the article does not.
 * - An excerpt match is a summary match.
 * - A body match is weak on its own — every article mentions "account" somewhere.
 */
class Search extends Component
{
    /** Words too common to be worth matching on in a support context. */
    private const STOP_WORDS = [
        'a', 'an', 'and', 'are', 'as', 'at', 'be', 'but', 'by', 'can', 'do', 'does', 'for', 'from',
        'how', 'i', 'if', 'in', 'is', 'it', 'me', 'my', 'not', 'of', 'on', 'or', 'the', 'to', 'was',
        'what', 'when', 'where', 'which', 'why', 'will', 'with', 'you', 'your',
    ];

    private const WEIGHT_TITLE_EXACT = 100.0;
    private const WEIGHT_TITLE = 25.0;
    private const WEIGHT_KEYWORDS = 18.0;
    private const WEIGHT_EXCERPT = 8.0;
    private const WEIGHT_BODY = 3.0;
    private const WEIGHT_CATEGORY = 4.0;

    /** @var array<string, string[]>|null term => the whole group it belongs to. */
    private ?array $_synonymMap = null;

    // Searching
    // -------------------------------------------------------------------------

    /**
     * @param array{
     *     siteId?: int|null,
     *     categoryId?: int|int[]|null,
     *     limit?: int,
     *     log?: bool,
     *     source?: string,
     *     minScore?: float,
     * } $options
     * @return SearchResult[] Best first.
     */
    public function find(string $query, array $options = []): array
    {
        $siteId = $options['siteId'] ?? Craft::$app->getSites()->getCurrentSite()->id;
        $limit = $options['limit'] ?? Plugin::getInstance()->getSettings()->articlesPerPage;
        $minScore = $options['minScore'] ?? 0.0;

        $terms = $this->tokenize($query);

        if ($terms === []) {
            return [];
        }

        $expanded = $this->expand($terms);
        $candidates = $this->candidates($expanded, $siteId, $options['categoryId'] ?? null);

        $results = [];

        foreach ($candidates as $article) {
            $result = $this->score($article, $terms, $expanded);

            if ($result->score > $minScore) {
                $results[] = $result;
            }
        }

        usort($results, static fn(SearchResult $a, SearchResult $b) => $b->score <=> $a->score);
        $results = array_slice($results, 0, $limit);

        if ($options['log'] ?? false) {
            $this->log($query, count($results), $siteId, $options['source'] ?? 'kb');
        }

        return $results;
    }

    /**
     * The best few matches for something somebody is part-way through typing.
     *
     * Used by the live dropdown and by the deflection panel. `minScore` is raised deliberately:
     * showing three weak matches to somebody who is already frustrated enough to be filling in a
     * support form is worse than showing none.
     *
     * @return SearchResult[]
     */
    public function suggest(string $text, array $options = []): array
    {
        return $this->find($text, $options + [
            'limit' => Plugin::getInstance()->getSettings()->searchSuggestionLimit,
            'minScore' => 6.0,
            'log' => false,
        ]);
    }

    /**
     * Candidate articles: anything CSR's own columns match, plus whatever Craft's index adds.
     *
     * Two passes merged, capped well above the page size so that scoring has something to choose
     * between without the query itself becoming the ranking.
     *
     * @param string[] $terms
     * @return Article[]
     */
    private function candidates(array $terms, ?int $siteId, mixed $categoryId): array
    {
        $user = Craft::$app->getUser()->getIdentity() ?? false;

        $base = static fn() => Article::find()
            ->siteId($siteId)
            ->forUser($user)
            ->status(Article::STATUS_ENABLED)
            ->limit(120);

        $query = $base()->matching($terms);

        if ($categoryId !== null) {
            $query->categoryId($categoryId);
        }

        /** @var Article[] $articles */
        $articles = $query->all();
        $found = [];

        foreach ($articles as $article) {
            $found[(int)$article->id] = $article;
        }

        // The body pass. Craft's index is asked for the original phrase rather than the expanded
        // term list, because a synonym group of six words turned into an OR search matches most of
        // the knowledge base and drowns the pass that was actually precise.
        try {
            $indexQuery = $base()->search(implode(' OR ', array_map(
                static fn(string $term) => '*' . $term . '*',
                array_slice($terms, 0, 6),
            )));

            if ($categoryId !== null) {
                $indexQuery->categoryId($categoryId);
            }

            foreach ($indexQuery->all() as $article) {
                $found[(int)$article->id] ??= $article;
            }
        } catch (\Throwable $exception) {
            // A missing or half-built search index must not take the search box down with it.
            Craft::info('CSR skipped the body search pass: ' . $exception->getMessage(), 'csr');
        }

        return array_values($found);
    }

    /**
     * @param string[] $terms The words the visitor actually typed.
     * @param string[] $expanded Those plus their synonyms.
     */
    private function score(Article $article, array $terms, array $expanded): SearchResult
    {
        $title = StringHelper::toLowerCase((string)$article->title);
        $keywords = StringHelper::toLowerCase((string)$article->keywords);
        $excerpt = StringHelper::toLowerCase((string)$article->excerpt);
        $body = StringHelper::toLowerCase($article->getPlainTextBody());
        $category = StringHelper::toLowerCase((string)$article->getCategory()?->name);

        $phrase = implode(' ', $terms);
        $score = 0.0;
        $matched = [];
        $matchedIn = [];

        // The whole phrase in the title is not a match, it is *the* answer.
        if ($phrase !== '' && str_contains($title, $phrase)) {
            $score += self::WEIGHT_TITLE_EXACT;
            $matchedIn['title'] = true;
        }

        foreach ($expanded as $index => $term) {
            // A synonym is worth less than the word somebody actually typed — otherwise "money
            // back" outranks "refund" on an article called Refunds.
            $weight = in_array($term, $terms, true) ? 1.0 : 0.6;
            $hit = false;

            if (str_contains($title, $term)) {
                $score += self::WEIGHT_TITLE * $weight;
                $matchedIn['title'] = true;
                $hit = true;
            }

            if ($keywords !== '' && str_contains($keywords, $term)) {
                $score += self::WEIGHT_KEYWORDS * $weight;
                $matchedIn['keywords'] = true;
                $hit = true;
            }

            if ($excerpt !== '' && str_contains($excerpt, $term)) {
                $score += self::WEIGHT_EXCERPT * $weight;
                $matchedIn['excerpt'] = true;
                $hit = true;
            }

            if ($body !== '' && str_contains($body, $term)) {
                $score += self::WEIGHT_BODY * $weight;
                $matchedIn['body'] = true;
                $hit = true;
            }

            if ($category !== '' && str_contains($category, $term)) {
                $score += self::WEIGHT_CATEGORY * $weight;
                $matchedIn['category'] = true;
                $hit = true;
            }

            if ($hit) {
                $matched[] = $term;
            }
        }

        if ($score > 0.0) {
            // An article readers say helped them is a better answer to the same query than one
            // they say did not, and a featured article is one somebody decided is the answer.
            // Both are nudges, not overrides — at most a fifth of a title match.
            $helpfulness = $article->getHasMeaningfulRating() ? ($article->getHelpfulness() ?? 0.5) : 0.5;
            $score += ($helpfulness - 0.5) * 10.0;

            if ($article->featured) {
                $score += 5.0;
            }
        }

        return new SearchResult($article, [
            'score' => round($score, 2),
            'matchedTerms' => array_values(array_unique($matched)),
            'matchedIn' => array_keys($matchedIn),
        ]);
    }

    // Terms
    // -------------------------------------------------------------------------

    /**
     * The searchable words in a phrase.
     *
     * @return string[]
     */
    public function tokenize(string $query): array
    {
        $normalized = $this->normalize($query);

        if ($normalized === '') {
            return [];
        }

        $minLength = max(1, Plugin::getInstance()->getSettings()->searchMinLength);
        $words = preg_split('/\s+/u', $normalized) ?: [];

        $terms = array_filter(
            $words,
            static fn(string $word) => mb_strlen($word) >= $minLength && !in_array($word, self::STOP_WORDS, true),
        );

        // A query made entirely of stop words — "how do I" — still deserves an attempt, and the
        // failed-search report deserves to know somebody typed it.
        if ($terms === []) {
            $terms = array_filter($words, static fn(string $word) => mb_strlen($word) >= $minLength);
        }

        return array_values(array_unique($terms));
    }

    /**
     * Fold a query into the form the analytics group on.
     *
     * `Refund?`, `REFUND` and `  refund ` are one failed search, and no amount of SQL will agree
     * unless somebody decides that here, once, and stores the result.
     */
    public function normalize(string $query): string
    {
        $query = StringHelper::toLowerCase(trim($query));
        $query = preg_replace('/[\p{P}\p{S}]+/u', ' ', $query) ?? $query;
        $query = preg_replace('/\s+/u', ' ', $query) ?? $query;

        return trim(mb_substr($query, 0, 255));
    }

    /**
     * The terms plus everything the site has said means the same thing.
     *
     * @param string[] $terms
     * @return string[]
     */
    public function expand(array $terms): array
    {
        $map = $this->getSynonymMap();
        $expanded = $terms;

        foreach ($terms as $term) {
            foreach ($map[$term] ?? [] as $synonym) {
                $expanded[] = $synonym;
            }
        }

        return array_values(array_unique($expanded));
    }

    /** @return array<string, string[]> */
    private function getSynonymMap(): array
    {
        if ($this->_synonymMap !== null) {
            return $this->_synonymMap;
        }

        $this->_synonymMap = [];

        foreach (Plugin::getInstance()->getSettings()->getSynonyms() as $group) {
            foreach ($group as $term) {
                $this->_synonymMap[$term] = array_values(array_diff($group, [$term]));
            }
        }

        return $this->_synonymMap;
    }

    // Logging
    // -------------------------------------------------------------------------

    /**
     * Write down what somebody looked for and how it went.
     *
     * One row per search rather than a counter per phrase, because the question worth asking is
     * not "how often was `refund` searched" but "how often was it searched *and nothing came
     * back*" — which needs the result count of each individual search — and "what did they open
     * afterwards", which needs the click. A counter can answer neither.
     */
    public function log(string $query, int $resultCount, ?int $siteId = null, string $source = 'kb'): ?int
    {
        $settings = Plugin::getInstance()->getSettings();

        if (!$settings->trackSearches) {
            return null;
        }

        $normalized = $this->normalize($query);

        if ($normalized === '') {
            return null;
        }

        $record = new SearchRecord();
        $record->siteId = $siteId ?? (int)Craft::$app->getSites()->getCurrentSite()->id;
        $record->query = mb_substr(trim($query), 0, 255);
        $record->normalizedQuery = $normalized;
        $record->resultCount = $resultCount;
        $record->visitorKey = Visitor::key();
        $record->userId = Craft::$app->getUser()->getId();
        $record->source = $source;
        $record->save(false);

        return (int)$record->id;
    }

    /**
     * Somebody opened one of the results.
     *
     * Attached to the visitor's most recent search of that phrase rather than to a search ID
     * passed around in the URL: the link out of a search results page is an ordinary link to an
     * ordinary article, and threading an ID through it would put a tracking parameter on every URL
     * anybody ever copies out of the help centre.
     */
    public function recordClick(string $query, int $articleId, ?int $siteId = null): bool
    {
        if (!Plugin::getInstance()->getSettings()->trackSearches) {
            return false;
        }

        $normalized = $this->normalize($query);

        if ($normalized === '') {
            return false;
        }

        /** @var SearchRecord|null $record */
        $record = SearchRecord::find()
            ->where([
                'normalizedQuery' => $normalized,
                'visitorKey' => Visitor::key(),
                'clickedArticleId' => null,
            ])
            ->andFilterWhere(['siteId' => $siteId])
            ->orderBy(['dateCreated' => SORT_DESC])
            ->one();

        if ($record === null) {
            return false;
        }

        $record->clickedArticleId = $articleId;

        return $record->save(false);
    }

    /**
     * Delete searches older than the retention window.
     *
     * Lite keeps thirty days, which is enough to answer "what happened this month" — the question
     * its top-searches report exists for — without leaving a table that grows forever behind on a
     * site nobody is administering.
     */
    public function prune(?int $days = null): int
    {
        $settings = Plugin::getInstance()->getSettings();
        $days ??= $settings->searchRetentionDays;

        $cap = \justinholtweb\csr\models\Edition::maxSearchRetentionDays(Plugin::getInstance()->isPro());

        if ($cap !== null) {
            $days = $days > 0 ? min($days, $cap) : $cap;
        }

        if ($days <= 0) {
            return 0;
        }

        $cutoff = (new DateTime())->modify("-{$days} days");

        return \craft\helpers\Db::delete(
            \justinholtweb\csr\records\Table::SEARCHES,
            ['<', 'dateCreated', \craft\helpers\Db::prepareDateForDb($cutoff)],
        );
    }

    public function refresh(): void
    {
        $this->_synonymMap = null;
    }
}
