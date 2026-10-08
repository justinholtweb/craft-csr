<?php

declare(strict_types=1);

namespace justinholtweb\csr\services;

use Craft;
use craft\base\Component;
use craft\db\Query;
use craft\helpers\DateTimeHelper;
use craft\helpers\Db;
use DateTime;
use justinholtweb\csr\elements\Article;
use justinholtweb\csr\elements\Ticket;
use justinholtweb\csr\models\Edition;
use justinholtweb\csr\models\Status;
use justinholtweb\csr\Plugin;
use justinholtweb\csr\records\Table;

/**
 * What the knowledge base and the desk are actually doing.
 *
 * The report this plugin exists for is {@see self::getFailedSearches()}. A knowledge base's most
 * valuable page is the one it does not have yet, and the list of phrases people searched for and
 * got nothing back is the only place that page's name is ever written down. Everything else here
 * is supporting evidence.
 *
 * Every method takes a window in days and a site, because "all time" is not a question anybody
 * asks about a support desk — it is a question about the history of the company.
 */
class Analytics extends Component
{
    // Search
    // -------------------------------------------------------------------------

    /**
     * What people looked for, most often first.
     *
     * In Lite as well as Pro: knowing what your customers ask about is not a premium feature, it
     * is the reason to have a knowledge base at all.
     *
     * @return array<int, array{query: string, searches: int, noResults: int, clicks: int, lastSearched: string}>
     */
    public function getTopSearches(int $days = 30, int $limit = 25, ?int $siteId = null): array
    {
        return $this->searchQuery($days, $siteId)
            ->orderBy(['searches' => SORT_DESC])
            ->limit($limit)
            ->all();
    }

    /**
     * The searches that came back with nothing.
     *
     * Ordered by how many people hit the same wall, so the top of the list is the article to write
     * next. `resultCount = 0` rather than "few results": a thin result set is a ranking problem,
     * an empty one is a missing page, and mixing them produces a list nobody can act on.
     *
     * @return array<int, array{query: string, searches: int, noResults: int, clicks: int, lastSearched: string}>
     */
    public function getFailedSearches(int $days = 30, int $limit = 25, ?int $siteId = null): array
    {
        if (!Edition::allowsFailedSearchAnalytics(Plugin::getInstance()->isPro())) {
            return [];
        }

        return $this->searchQuery($days, $siteId)
            ->andHaving(['>', 'noResults', 0])
            ->orderBy(['noResults' => SORT_DESC, 'searches' => SORT_DESC])
            ->limit($limit)
            ->all();
    }

    /**
     * Searches that returned results and were abandoned anyway.
     *
     * A different and often more interesting failure than an empty page: the knowledge base has
     * something to say and the summary is not convincing anybody to click it. Usually a title
     * problem, occasionally an excerpt problem, never a "write a new article" problem — which is
     * why it is reported separately from the empty ones.
     *
     * @return array<int, array{query: string, searches: int, noResults: int, clicks: int, lastSearched: string}>
     */
    public function getUnclickedSearches(int $days = 30, int $limit = 25, ?int $siteId = null): array
    {
        if (!Edition::allowsFailedSearchAnalytics(Plugin::getInstance()->isPro())) {
            return [];
        }

        return $this->searchQuery($days, $siteId)
            ->andHaving(['>', 'searches', 2])
            ->andHaving(['=', 'clicks', 0])
            ->andHaving(['=', 'noResults', 0])
            ->orderBy(['searches' => SORT_DESC])
            ->limit($limit)
            ->all();
    }

    private function searchQuery(int $days, ?int $siteId): Query
    {
        $query = (new Query())
            ->select([
                'query' => 'normalizedQuery',
                'searches' => 'COUNT(*)',
                'noResults' => 'SUM(CASE WHEN [[resultCount]] = 0 THEN 1 ELSE 0 END)',
                'clicks' => 'SUM(CASE WHEN [[clickedArticleId]] IS NOT NULL THEN 1 ELSE 0 END)',
                'lastSearched' => 'MAX([[dateCreated]])',
            ])
            ->from([Table::SEARCHES])
            ->groupBy(['normalizedQuery']);

        if ($days > 0) {
            $query->where(['>=', 'dateCreated', Db::prepareDateForDb((new DateTime())->modify("-{$days} days"))]);
        }

        if ($siteId !== null) {
            $query->andWhere(['siteId' => $siteId]);
        }

        return $query;
    }

    /** @return array{searches: int, failed: int, clicked: int, failureRate: float|null} */
    public function getSearchTotals(int $days = 30, ?int $siteId = null): array
    {
        $query = (new Query())
            ->select([
                'searches' => 'COUNT(*)',
                'failed' => 'SUM(CASE WHEN [[resultCount]] = 0 THEN 1 ELSE 0 END)',
                'clicked' => 'SUM(CASE WHEN [[clickedArticleId]] IS NOT NULL THEN 1 ELSE 0 END)',
            ])
            ->from([Table::SEARCHES]);

        if ($days > 0) {
            $query->where(['>=', 'dateCreated', Db::prepareDateForDb((new DateTime())->modify("-{$days} days"))]);
        }

        if ($siteId !== null) {
            $query->andWhere(['siteId' => $siteId]);
        }

        $row = $query->one() ?: [];
        $searches = (int)($row['searches'] ?? 0);

        return [
            'searches' => $searches,
            'failed' => (int)($row['failed'] ?? 0),
            'clicked' => (int)($row['clicked'] ?? 0),
            'failureRate' => $searches > 0 ? (float)((int)($row['failed'] ?? 0) / $searches) : null,
        ];
    }

    // Articles
    // -------------------------------------------------------------------------

    /**
     * How each article is doing: read, rated, and whether it stops tickets.
     *
     * @return array<int, array{article: Article, views: int, helpful: int, unhelpful: int, helpfulness: float|null}>
     */
    public function getArticlePerformance(int $days = 30, int $limit = 25, ?int $siteId = null): array
    {
        if (!Edition::allowsArticleAnalytics(Plugin::getInstance()->isPro())) {
            return [];
        }

        $views = (new Query())
            ->select(['articleId', 'total' => 'SUM([[views]])'])
            ->from([Table::VIEWS])
            ->groupBy(['articleId'])
            ->orderBy(['total' => SORT_DESC])
            ->limit($limit);

        if ($days > 0) {
            $views->where(['>=', 'date', (new DateTime())->modify("-{$days} days")->format('Y-m-d')]);
        }

        if ($siteId !== null) {
            $views->andWhere(['siteId' => $siteId]);
        }

        $rows = $views->all();

        if ($rows === []) {
            return [];
        }

        $articles = [];

        foreach (Article::find()->id(array_column($rows, 'articleId'))->siteId('*')->unique()->status(null)->all() as $article) {
            $articles[(int)$article->id] = $article;
        }

        $performance = [];

        foreach ($rows as $row) {
            $article = $articles[(int)$row['articleId']] ?? null;

            if ($article === null) {
                continue;
            }

            $performance[] = [
                'article' => $article,
                'views' => (int)$row['total'],
                'helpful' => $article->helpfulCount,
                'unhelpful' => $article->unhelpfulCount,
                'helpfulness' => $article->getHelpfulness(),
            ];
        }

        return $performance;
    }

    /**
     * Articles readers keep saying do not help.
     *
     * Five votes minimum: below that, "20% helpful" is one person having a bad morning, and
     * sending somebody off to rewrite a page on that evidence wastes the one afternoon a week
     * anybody has for documentation.
     *
     * @return Article[]
     */
    public function getUnhelpfulArticles(int $limit = 10, ?int $siteId = null): array
    {
        if (!Edition::allowsArticleAnalytics(Plugin::getInstance()->isPro())) {
            return [];
        }

        return Article::find()
            ->siteId($siteId ?? '*')
            ->unique()
            ->status(null)
            ->unhelpful()
            ->orderBy(['csr_articles.unhelpfulCount' => SORT_DESC])
            ->limit($limit)
            ->all();
    }

    /**
     * Articles nobody has read at all in the window.
     *
     * The other end of the same question. A knowledge base accumulates pages that were written
     * for a product that no longer works that way, and nothing else ever surfaces them.
     *
     * @return Article[]
     */
    public function getUnreadArticles(int $days = 90, int $limit = 25, ?int $siteId = null): array
    {
        if (!Edition::allowsArticleAnalytics(Plugin::getInstance()->isPro())) {
            return [];
        }

        $read = (new Query())
            ->select(['articleId'])
            ->distinct()
            ->from([Table::VIEWS])
            ->where(['>=', 'date', (new DateTime())->modify("-{$days} days")->format('Y-m-d')])
            ->column();

        $query = Article::find()
            ->siteId($siteId ?? '*')
            ->unique()
            ->status(null)
            ->limit($limit)
            ->orderBy(['elements.dateCreated' => SORT_ASC]);

        if ($read !== []) {
            $query->id(array_merge(['not'], array_map('intval', $read)));
        }

        return $query->all();
    }

    /** Daily views, for a sparkline. @return array<string, int> `Y-m-d` => views */
    public function getViewTrend(int $days = 30, ?int $articleId = null, ?int $siteId = null): array
    {
        $query = (new Query())
            ->select(['date', 'total' => 'SUM([[views]])'])
            ->from([Table::VIEWS])
            ->where(['>=', 'date', (new DateTime())->modify("-{$days} days")->format('Y-m-d')])
            ->groupBy(['date'])
            ->orderBy(['date' => SORT_ASC]);

        if ($articleId !== null) {
            $query->andWhere(['articleId' => $articleId]);
        }

        if ($siteId !== null) {
            $query->andWhere(['siteId' => $siteId]);
        }

        $trend = [];

        // Every day in the window, including the empty ones — a chart that silently skips the days
        // with no traffic draws a flat line through an outage.
        for ($offset = $days; $offset >= 0; $offset--) {
            $trend[(new DateTime())->modify("-{$offset} days")->format('Y-m-d')] = 0;
        }

        foreach ($query->all() as $row) {
            $trend[(string)$row['date']] = (int)$row['total'];
        }

        return $trend;
    }

    // The desk
    // -------------------------------------------------------------------------

    /**
     * @return array{
     *     raised: int, resolved: int, open: int, backlog: int,
     *     firstResponseMinutes: float|null, resolutionMinutes: float|null,
     *     firstResponseBreaches: int, resolutionBreaches: int,
     *     satisfaction: float|null, satisfactionCount: int, available: bool
     * }
     */
    public function getDeskSummary(int $days = 30, ?int $siteId = null): array
    {
        $available = Edition::allowsDeskReports(Plugin::getInstance()->isPro());

        $summary = [
            'raised' => 0, 'resolved' => 0, 'open' => 0, 'backlog' => 0,
            'firstResponseMinutes' => null, 'resolutionMinutes' => null,
            'firstResponseBreaches' => 0, 'resolutionBreaches' => 0,
            'satisfaction' => null, 'satisfactionCount' => 0,
            'available' => $available,
        ];

        if (!$available) {
            return $summary;
        }

        $since = Db::prepareDateForDb((new DateTime())->modify("-{$days} days"));

        $row = (new Query())
            ->select([
                'raised' => 'COUNT(*)',
                'resolved' => 'SUM(CASE WHEN [[resolvedAt]] IS NOT NULL THEN 1 ELSE 0 END)',
                'firstResponseBreaches' => 'SUM(CASE WHEN [[firstResponseBreached]] = 1 THEN 1 ELSE 0 END)',
                'resolutionBreaches' => 'SUM(CASE WHEN [[resolutionBreached]] = 1 THEN 1 ELSE 0 END)',
                'satisfaction' => 'AVG([[satisfaction]])',
                'satisfactionCount' => 'SUM(CASE WHEN [[satisfaction]] IS NOT NULL THEN 1 ELSE 0 END)',
            ])
            ->from([Table::TICKETS])
            ->where(['isSpam' => false])
            ->andWhere(['>=', 'submittedAt', $since])
            ->andFilterWhere(['submittedSiteId' => $siteId])
            ->one() ?: [];

        $summary['raised'] = (int)($row['raised'] ?? 0);
        $summary['resolved'] = (int)($row['resolved'] ?? 0);
        $summary['firstResponseBreaches'] = (int)($row['firstResponseBreaches'] ?? 0);
        $summary['resolutionBreaches'] = (int)($row['resolutionBreaches'] ?? 0);
        $summary['satisfaction'] = $row['satisfaction'] !== null ? (float)$row['satisfaction'] : null;
        $summary['satisfactionCount'] = (int)($row['satisfactionCount'] ?? 0);

        $summary['firstResponseMinutes'] = $this->averageMinutes('submittedAt', 'firstRespondedAt', $since, $siteId);
        $summary['resolutionMinutes'] = $this->averageMinutes('submittedAt', 'resolvedAt', $since, $siteId);

        $counts = Plugin::getInstance()->tickets->getQueueCounts();
        $summary['open'] = $counts['open'];
        $summary['backlog'] = $counts['awaitingFirstResponse'];

        return $summary;
    }

    /**
     * The mean gap between two stamps, in minutes.
     *
     * Computed in PHP from the raw pairs rather than with a database date-difference function,
     * because `TIMESTAMPDIFF` and `EXTRACT(EPOCH FROM …)` are not the same function, and a report
     * that only works on MySQL is a report that fails on the one install that reports it.
     */
    private function averageMinutes(string $from, string $to, string $since, ?int $siteId): ?float
    {
        $rows = (new Query())
            ->select([$from, $to])
            ->from([Table::TICKETS])
            ->where(['isSpam' => false])
            ->andWhere(['not', [$to => null]])
            ->andWhere(['>=', $from, $since])
            ->andFilterWhere(['submittedSiteId' => $siteId])
            ->limit(5000)
            ->all();

        if ($rows === []) {
            return null;
        }

        $total = 0;
        $count = 0;

        foreach ($rows as $row) {
            $start = strtotime((string)$row[$from] . ' UTC');
            $end = strtotime((string)$row[$to] . ' UTC');

            if ($start !== false && $end !== false && $end >= $start) {
                $total += $end - $start;
                $count++;
            }
        }

        return $count > 0 ? round($total / $count / 60, 1) : null;
    }

    /**
     * Who is carrying what.
     *
     * @return array<int, array{agentId: int, name: string, open: int, resolved: int, satisfaction: float|null}>
     */
    public function getAgentWorkload(int $days = 30): array
    {
        if (!Edition::allowsDeskReports(Plugin::getInstance()->isPro())) {
            return [];
        }

        $openIds = array_map(
            static fn(Status $status) => (int)$status->id,
            array_filter(Plugin::getInstance()->statuses->getAllStatuses(), static fn(Status $s) => $s->isOpen()),
        );

        $since = Db::prepareDateForDb((new DateTime())->modify("-{$days} days"));

        $rows = (new Query())
            ->select([
                'agentId',
                'open' => 'SUM(CASE WHEN ' . ($openIds !== [] ? '[[statusId]] IN (' . implode(',', $openIds) . ')' : '1=0') . ' THEN 1 ELSE 0 END)',
                'resolved' => 'SUM(CASE WHEN [[resolvedAt]] IS NOT NULL AND [[resolvedAt]] >= :since THEN 1 ELSE 0 END)',
                'satisfaction' => 'AVG([[satisfaction]])',
            ])
            ->from([Table::TICKETS])
            ->where(['isSpam' => false])
            ->andWhere(['not', ['agentId' => null]])
            ->params([':since' => $since])
            ->groupBy(['agentId'])
            ->all();

        $workload = [];

        foreach ($rows as $row) {
            $user = Craft::$app->getUsers()->getUserById((int)$row['agentId']);

            $workload[] = [
                'agentId' => (int)$row['agentId'],
                'name' => $user?->getFriendlyName() ?? Craft::t('csr', 'A deleted account'),
                'open' => (int)$row['open'],
                'resolved' => (int)$row['resolved'],
                'satisfaction' => $row['satisfaction'] !== null ? round((float)$row['satisfaction'], 2) : null,
            ];
        }

        usort($workload, static fn(array $a, array $b) => $b['open'] <=> $a['open']);

        return $workload;
    }

    /**
     * Which subjects generate the most tickets.
     *
     * Read next to the knowledge base's coverage of the same categories, this is the whole
     * argument for writing documentation: the category at the top of this list and the bottom of
     * the article count is where the next afternoon should go.
     *
     * @return array<int, array{category: string, categoryId: int|null, tickets: int, articles: int}>
     */
    public function getTicketsByCategory(int $days = 30): array
    {
        $since = Db::prepareDateForDb((new DateTime())->modify("-{$days} days"));

        $tickets = (new Query())
            ->select(['categoryId', 'total' => 'COUNT(*)'])
            ->from([Table::TICKETS])
            ->where(['isSpam' => false])
            ->andWhere(['>=', 'submittedAt', $since])
            ->groupBy(['categoryId'])
            ->orderBy(['total' => SORT_DESC])
            ->all();

        $articles = (new Query())
            ->select(['categoryId', 'total' => 'COUNT(*)'])
            ->from([Table::ARTICLES])
            ->groupBy(['categoryId'])
            ->pairs();

        $categories = Plugin::getInstance()->categories;
        $rows = [];

        foreach ($tickets as $row) {
            $id = $row['categoryId'] !== null ? (int)$row['categoryId'] : null;

            $rows[] = [
                'category' => $id !== null
                    ? (string)($categories->getCategoryById($id)->name ?? Craft::t('csr', 'A deleted category'))
                    : Craft::t('csr', 'Not filed'),
                'categoryId' => $id,
                'tickets' => (int)$row['total'],
                'articles' => (int)($articles[$id] ?? 0),
            ];
        }

        return $rows;
    }

    /** Tickets raised per day, for a chart. @return array<string, int> */
    public function getTicketTrend(int $days = 30, ?int $siteId = null): array
    {
        // Grouped in PHP rather than with `DATE(…)`: that is a MySQL function, Postgres spells the
        // same idea `CAST(… AS DATE)`, and a report that only works on one driver is a report
        // that fails on the one install that runs it.
        $rows = (new Query())
            ->select(['submittedAt'])
            ->from([Table::TICKETS])
            ->where(['isSpam' => false])
            ->andWhere(['>=', 'submittedAt', Db::prepareDateForDb((new DateTime())->modify("-{$days} days"))])
            ->andFilterWhere(['submittedSiteId' => $siteId])
            ->limit(20000)
            ->column();

        $trend = [];

        for ($offset = $days; $offset >= 0; $offset--) {
            $trend[(new DateTime())->modify("-{$offset} days")->format('Y-m-d')] = 0;
        }

        foreach ($rows as $submittedAt) {
            // The stored string carries no timezone, and Craft wrote it as UTC — parsing it in
            // PHP's default zone would file a ticket raised at 6pm under the wrong day.
            $date = DateTimeHelper::toDateTime($submittedAt, true);
            $day = $date ? $date->format('Y-m-d') : null;

            if ($day !== null && isset($trend[$day])) {
                $trend[$day]++;
            }
        }

        return $trend;
    }

    /**
     * The one number the whole plugin is arguing for.
     *
     * Tickets raised against tickets deflected, over the same window. Neither figure means much
     * alone; together they are the answer to "is writing documentation worth the afternoon".
     *
     * @return array{tickets: int, deflected: int, ratio: float|null, available: bool}
     */
    public function getDeflectionValue(int $days = 30, ?int $siteId = null): array
    {
        $stats = Plugin::getInstance()->deflection->getStats($days, $siteId);

        $tickets = (int)Ticket::find()
            ->isSpam(false)
            ->submittedAfter((new DateTime())->modify("-{$days} days"))
            ->submittedSiteId($siteId)
            ->count();

        return [
            'tickets' => $tickets,
            'deflected' => $stats['deflected'],
            'ratio' => $tickets + $stats['deflected'] > 0
                ? (float)($stats['deflected'] / ($tickets + $stats['deflected']))
                : null,
            'available' => $stats['available'],
        ];
    }
}
