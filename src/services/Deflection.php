<?php

declare(strict_types=1);

namespace justinholtweb\csr\services;

use Craft;
use craft\base\Component;
use craft\db\Query;
use craft\helpers\Db;
use craft\helpers\Json;
use DateTime;
use justinholtweb\csr\elements\Article;
use justinholtweb\csr\elements\Ticket;
use justinholtweb\csr\enums\DeflectionOutcome;
use justinholtweb\csr\helpers\Visitor;
use justinholtweb\csr\models\Edition;
use justinholtweb\csr\models\SearchResult;
use justinholtweb\csr\Plugin;
use justinholtweb\csr\records\DeflectionRecord;
use justinholtweb\csr\records\Table;

/**
 * Showing somebody the answer before they write in, and counting whether it worked.
 *
 * This is the feature the whole plugin is built around. A support desk's cheapest ticket is the
 * one nobody raises, and the moment to prevent it is the two seconds between typing a subject line
 * and pressing send — not in a knowledge base somebody would have had to think to visit.
 *
 * ## Why the outcome is recorded explicitly
 *
 * Because "they went away" and "they were helped" look identical from the server. A deflection
 * rate inferred from an absence is really a measurement of how many laptops ran out of battery.
 * So the panel asks — one button, *"Yes, that answers it"* — and the number this service reports
 * is the number of people who pressed it.
 *
 * ## The suggestions are in Lite; the counting is Pro
 *
 * Gating the panel would make Lite pointless: it is the thing CSR is *for*. What Pro adds is
 * knowing whether it works, which is what somebody deciding what to write next needs.
 */
class Deflection extends Component
{
    /**
     * Articles to offer somebody who has typed this much of a subject line.
     *
     * @return SearchResult[]
     */
    public function suggest(string $text, ?int $categoryId = null, ?int $siteId = null): array
    {
        $settings = Plugin::getInstance()->getSettings();

        if (!$settings->deflectionEnabled || mb_strlen(trim($text)) < $settings->deflectionMinChars) {
            return [];
        }

        $options = [
            'siteId' => $siteId,
            'limit' => max(1, $settings->deflectionCount),
            'log' => false,
        ];

        // Search inside the chosen subject first — somebody filing a Billing ticket almost always
        // wants a Billing article, and the narrower pass makes the suggestions visibly relevant
        // rather than merely plausible.
        if ($categoryId !== null) {
            $categoryIds = Plugin::getInstance()->categories->getIdsWithDescendants($categoryId);
            $results = Plugin::getInstance()->search->suggest($text, $options + ['categoryId' => $categoryIds]);

            if ($results !== []) {
                return $results;
            }
        }

        return Plugin::getInstance()->search->suggest($text, $options);
    }

    /**
     * Remember that these articles were offered, so the outcome means something later.
     *
     * One row per *session*, updated as the visitor keeps typing, rather than one per keystroke: a
     * deflection rate is a ratio of people, and one visitor being shown six articles is one person
     * deciding once.
     *
     * @param SearchResult[] $results
     */
    public function record(string $text, array $results, ?int $categoryId = null, ?int $siteId = null): ?int
    {
        if (!Plugin::getInstance()->getSettings()->deflectionEnabled) {
            return null;
        }

        $siteId ??= (int)Craft::$app->getSites()->getCurrentSite()->id;
        $record = $this->currentRecord($siteId);

        $record->siteId = $siteId;
        $record->visitorKey = Visitor::key();
        $record->query = mb_substr(trim($text), 0, 500);
        $record->categoryId = $categoryId;
        $record->suggestedIds = Json::encode(array_map(
            static fn(SearchResult $result) => (int)$result->article->id,
            $results,
        ));

        return $record->save(false) ? (int)$record->id : null;
    }

    /** They opened one of the suggestions. Not yet a deflection — they may still write in. */
    public function recordClick(int $articleId, ?int $siteId = null): bool
    {
        $record = $this->currentRecord($siteId ?? (int)Craft::$app->getSites()->getCurrentSite()->id, false);

        if ($record === null) {
            return false;
        }

        $record->clickedArticleId = $articleId;

        return $record->save(false);
    }

    /** They said it answered the question, and no ticket was raised. */
    public function recordDeflected(?int $articleId = null, ?int $siteId = null): bool
    {
        $record = $this->currentRecord($siteId ?? (int)Craft::$app->getSites()->getCurrentSite()->id, false);

        if ($record === null) {
            return false;
        }

        $record->outcome = DeflectionOutcome::Deflected->value;

        if ($articleId !== null) {
            $record->clickedArticleId = $articleId;
        }

        return $record->save(false);
    }

    /** They read the suggestions and raised a ticket anyway. Attached so both halves are countable. */
    public function recordSubmitted(Ticket $ticket): void
    {
        $record = $this->currentRecord((int)($ticket->submittedSiteId ?? Craft::$app->getSites()->getCurrentSite()->id), false);

        if ($record === null) {
            return;
        }

        $record->outcome = DeflectionOutcome::Submitted->value;
        $record->ticketId = (int)$ticket->id;
        $record->save(false);

        $ticket->deflectionId = (int)$record->id;
    }

    /**
     * The visitor's open deflection session, or a new one.
     *
     * "Open" means: same visitor, same site, still pending, and started within the hour. Without
     * the time bound, somebody who came back a week later would have their new question folded
     * into an old session and counted as one decision.
     */
    private function currentRecord(int $siteId, bool $createIfMissing = true): ?DeflectionRecord
    {
        $since = Db::prepareDateForDb(
            (new DateTime())->modify('-' . Visitor::SESSION_WINDOW_MINUTES . ' minutes'),
        );

        $record = DeflectionRecord::find()
            ->where([
                'visitorKey' => Visitor::key(),
                'siteId' => $siteId,
                'outcome' => DeflectionOutcome::Pending->value,
            ])
            ->andWhere(['>=', 'dateCreated', $since])
            ->orderBy(['dateCreated' => SORT_DESC])
            ->one();

        if ($record !== null) {
            return $record;
        }

        return $createIfMissing ? new DeflectionRecord() : null;
    }

    // Reporting
    // -------------------------------------------------------------------------

    /**
     * How the suggestions panel is doing.
     *
     * @return array{
     *     shown: int, deflected: int, submitted: int, abandoned: int,
     *     rate: float|null, clickRate: float|null, available: bool
     * }
     */
    public function getStats(?int $days = 30, ?int $siteId = null): array
    {
        $empty = [
            'shown' => 0, 'deflected' => 0, 'submitted' => 0, 'abandoned' => 0,
            'rate' => null, 'clickRate' => null,
            'available' => Edition::allowsDeflectionAnalytics(Plugin::getInstance()->isPro()),
        ];

        if (!$empty['available']) {
            return $empty;
        }

        $query = (new Query())
            ->select([
                'shown' => 'COUNT(*)',
                'deflected' => 'SUM(CASE WHEN [[outcome]] = :deflected THEN 1 ELSE 0 END)',
                'submitted' => 'SUM(CASE WHEN [[outcome]] = :submitted THEN 1 ELSE 0 END)',
                'abandoned' => 'SUM(CASE WHEN [[outcome]] = :pending OR [[outcome]] = :abandoned THEN 1 ELSE 0 END)',
                'clicked' => 'SUM(CASE WHEN [[clickedArticleId]] IS NOT NULL THEN 1 ELSE 0 END)',
            ])
            ->from([Table::DEFLECTIONS])
            ->params([
                ':deflected' => DeflectionOutcome::Deflected->value,
                ':submitted' => DeflectionOutcome::Submitted->value,
                ':pending' => DeflectionOutcome::Pending->value,
                ':abandoned' => DeflectionOutcome::Abandoned->value,
            ]);

        if ($days !== null && $days > 0) {
            $query->where(['>=', 'dateCreated', Db::prepareDateForDb((new DateTime())->modify("-{$days} days"))]);
        }

        if ($siteId !== null) {
            $query->andWhere(['siteId' => $siteId]);
        }

        $row = $query->one() ?: [];

        $shown = (int)($row['shown'] ?? 0);
        $deflected = (int)($row['deflected'] ?? 0);
        $submitted = (int)($row['submitted'] ?? 0);
        $decided = $deflected + $submitted;

        return [
            'shown' => $shown,
            'deflected' => $deflected,
            'submitted' => $submitted,
            'abandoned' => (int)($row['abandoned'] ?? 0),
            // Of the people who decided, how many decided they did not need us. Sessions that were
            // simply abandoned are in neither half: counting them as deflections would let a
            // broken form report a spectacular deflection rate.
            //
            // Cast, because PHP's `/` returns an **int** when both operands are ints and the
            // division is exact — so a perfect 1/1 deflection rate comes back as `int(1)` while
            // 1/3 comes back as a float. Anything comparing the type, or writing it to JSON and
            // reading it back, then disagrees with itself depending on the numbers.
            'rate' => $decided > 0 ? (float)($deflected / $decided) : null,
            'clickRate' => $shown > 0 ? (float)((int)($row['clicked'] ?? 0) / $shown) : null,
            'available' => true,
        ];
    }

    /**
     * Which articles actually stop tickets.
     *
     * Worth more than a view count: an article that is read three hundred times and deflects
     * nothing is a page people bounce off, and this is the only report that can tell them apart.
     *
     * @return array<int, array{article: Article, deflected: int, clicked: int}>
     */
    public function getTopDeflectors(int $limit = 10, ?int $days = 30): array
    {
        if (!Edition::allowsDeflectionAnalytics(Plugin::getInstance()->isPro())) {
            return [];
        }

        $query = (new Query())
            ->select([
                'clickedArticleId',
                'clicked' => 'COUNT(*)',
                'deflected' => 'SUM(CASE WHEN [[outcome]] = :deflected THEN 1 ELSE 0 END)',
            ])
            ->from([Table::DEFLECTIONS])
            ->where(['not', ['clickedArticleId' => null]])
            ->params([':deflected' => DeflectionOutcome::Deflected->value])
            ->groupBy(['clickedArticleId'])
            ->orderBy(['deflected' => SORT_DESC, 'clicked' => SORT_DESC])
            ->limit($limit);

        if ($days !== null && $days > 0) {
            $query->andWhere(['>=', 'dateCreated', Db::prepareDateForDb((new DateTime())->modify("-{$days} days"))]);
        }

        $rows = $query->all();

        if ($rows === []) {
            return [];
        }

        $articles = [];

        foreach (Article::find()->id(array_column($rows, 'clickedArticleId'))->siteId('*')->unique()->all() as $article) {
            $articles[(int)$article->id] = $article;
        }

        $results = [];

        foreach ($rows as $row) {
            $article = $articles[(int)$row['clickedArticleId']] ?? null;

            if ($article !== null) {
                $results[] = [
                    'article' => $article,
                    'deflected' => (int)$row['deflected'],
                    'clicked' => (int)$row['clicked'],
                ];
            }
        }

        return $results;
    }

    /**
     * Mark old pending sessions as abandoned, so the rate is not diluted by open ones.
     *
     * Run from garbage collection. A session that is still pending an hour later is not going to
     * become anything else.
     */
    public function closeStaleSessions(): int
    {
        $cutoff = Db::prepareDateForDb(
            (new DateTime())->modify('-' . Visitor::SESSION_WINDOW_MINUTES . ' minutes'),
        );

        return Db::update(
            Table::DEFLECTIONS,
            ['outcome' => DeflectionOutcome::Abandoned->value],
            ['and', ['outcome' => DeflectionOutcome::Pending->value], ['<', 'dateCreated', $cutoff]],
            [],
            false,
        );
    }

    public function prune(int $days): int
    {
        if ($days <= 0) {
            return 0;
        }

        $cutoff = Db::prepareDateForDb((new DateTime())->modify("-{$days} days"));

        // Sessions that produced a ticket are kept regardless: the ticket points at them, and a
        // dangling `deflectionId` would make the "did this ticket read the suggestions" question
        // unanswerable for exactly the tickets somebody is still working on.
        return Db::delete(Table::DEFLECTIONS, [
            'and',
            ['<', 'dateCreated', $cutoff],
            ['ticketId' => null],
        ]);
    }
}
