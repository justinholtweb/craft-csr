<?php

declare(strict_types=1);

namespace justinholtweb\csr\services;

use Craft;
use craft\base\Component;
use craft\db\Query;
use craft\helpers\Db;
use DateTime;
use justinholtweb\csr\elements\Article;
use justinholtweb\csr\helpers\Visitor;
use justinholtweb\csr\models\Rating;
use justinholtweb\csr\Plugin;
use justinholtweb\csr\records\RatingRecord;
use justinholtweb\csr\records\Table;
use yii\db\Expression;

/**
 * "Was this helpful?", and what to do with the answer.
 *
 * One vote per visitor per article, changeable. An article whose score can only go down is
 * measuring impatience rather than usefulness: somebody who says no, reads on, and finds the
 * answer three paragraphs later should be able to say so.
 *
 * The comment box only appears after a **no**, and that is the highest-value free text in the
 * plugin — it is asked at the exact moment somebody is both motivated to answer and holding the
 * sentence the article should have contained.
 */
class Ratings extends Component
{
    /**
     * Record a vote, or change one already cast.
     *
     * @return Rating|null Null when the article does not accept ratings.
     */
    public function rate(Article $article, bool $helpful, ?string $comment = null, ?int $siteId = null): ?Rating
    {
        if (!$article->allowRatings || !Plugin::getInstance()->getSettings()->showRatings) {
            return null;
        }

        $siteId ??= (int)$article->siteId;
        $visitorKey = Visitor::key();

        $record = RatingRecord::findOne([
            'articleId' => $article->id,
            'visitorKey' => $visitorKey,
        ]);

        $previous = $record !== null ? (bool)$record->helpful : null;
        $record ??= new RatingRecord();

        $record->articleId = (int)$article->id;
        $record->siteId = $siteId;
        $record->visitorKey = $visitorKey;
        $record->userId = Craft::$app->getUser()->getId();
        $record->helpful = $helpful;

        // A comment already given is not erased by a later vote that came without one — somebody
        // pressing the button twice must not delete what they took the trouble to write.
        if ($comment !== null && trim($comment) !== '') {
            $record->comment = mb_substr(trim($comment), 0, 2000);
        }

        if (!$record->save(false)) {
            return null;
        }

        $this->adjustCounters($article, $previous, $helpful);

        return new Rating([
            'id' => (int)$record->id,
            'articleId' => (int)$record->articleId,
            'siteId' => (int)$record->siteId,
            'userId' => $record->userId !== null ? (int)$record->userId : null,
            'helpful' => (bool)$record->helpful,
            'comment' => $record->comment,
            'visitorKey' => $record->visitorKey,
        ]);
    }

    /**
     * Move the denormalised counters by exactly what changed.
     *
     * `CASE WHEN … ELSE 0 END` rather than `GREATEST(0, count - 1)`: MySQL evaluates the
     * subtraction before `GREATEST` sees it, so decrementing a counter already at zero raises
     * "BIGINT UNSIGNED value is out of range" and fails the whole request. The CASE never performs
     * the subtraction that would underflow, and is portable to Postgres besides.
     */
    private function adjustCounters(Article $article, ?bool $previous, bool $helpful): void
    {
        if ($previous === $helpful) {
            return;
        }

        $columns = [];

        if ($helpful) {
            $columns['helpfulCount'] = new Expression('[[helpfulCount]] + 1');
        } else {
            $columns['unhelpfulCount'] = new Expression('[[unhelpfulCount]] + 1');
        }

        if ($previous === true) {
            $columns['helpfulCount'] = new Expression('CASE WHEN [[helpfulCount]] >= 1 THEN [[helpfulCount]] - 1 ELSE 0 END');
        } elseif ($previous === false) {
            $columns['unhelpfulCount'] = new Expression('CASE WHEN [[unhelpfulCount]] >= 1 THEN [[unhelpfulCount]] - 1 ELSE 0 END');
        }

        Db::update(Table::ARTICLES, $columns, ['id' => $article->id], [], false);

        // Keep the element in hand consistent with the row, so the page that just took the vote
        // renders the new number rather than the old one.
        if ($helpful) {
            $article->helpfulCount++;
        } else {
            $article->unhelpfulCount++;
        }

        if ($previous === true) {
            $article->helpfulCount = max(0, $article->helpfulCount - 1);
        } elseif ($previous === false) {
            $article->unhelpfulCount = max(0, $article->unhelpfulCount - 1);
        }
    }

    /** What this visitor already said about this article, if anything. */
    public function getExistingVote(Article $article): ?bool
    {
        $helpful = (new Query())
            ->select(['helpful'])
            ->from([Table::RATINGS])
            ->where(['articleId' => $article->id, 'visitorKey' => Visitor::key()])
            ->scalar();

        return $helpful === false || $helpful === null ? null : (bool)$helpful;
    }

    /**
     * The comments left by readers who said an article did not help.
     *
     * @return array<int, array{comment: string, dateCreated: string, articleId: int}>
     */
    public function getFeedback(?int $articleId = null, int $limit = 50, ?int $days = null): array
    {
        $query = (new Query())
            ->select(['articleId', 'comment', 'dateCreated'])
            ->from([Table::RATINGS])
            ->where(['helpful' => false])
            ->andWhere(['not', ['comment' => null]])
            ->andWhere(['not', ['comment' => '']])
            ->orderBy(['dateCreated' => SORT_DESC])
            ->limit($limit);

        if ($articleId !== null) {
            $query->andWhere(['articleId' => $articleId]);
        }

        if ($days !== null && $days > 0) {
            $query->andWhere(['>=', 'dateCreated', Db::prepareDateForDb((new DateTime())->modify("-{$days} days"))]);
        }

        return $query->all();
    }

    /** @return array{helpful: int, unhelpful: int, comments: int} */
    public function getTotals(?int $days = null): array
    {
        $query = (new Query())
            ->select([
                'helpful' => 'SUM(CASE WHEN [[helpful]] = 1 THEN 1 ELSE 0 END)',
                'unhelpful' => 'SUM(CASE WHEN [[helpful]] = 0 THEN 1 ELSE 0 END)',
                'comments' => 'SUM(CASE WHEN [[comment]] IS NOT NULL AND [[comment]] != \'\' THEN 1 ELSE 0 END)',
            ])
            ->from([Table::RATINGS]);

        if ($days !== null && $days > 0) {
            $query->where(['>=', 'dateCreated', Db::prepareDateForDb((new DateTime())->modify("-{$days} days"))]);
        }

        $row = $query->one() ?: [];

        return [
            'helpful' => (int)($row['helpful'] ?? 0),
            'unhelpful' => (int)($row['unhelpful'] ?? 0),
            'comments' => (int)($row['comments'] ?? 0),
        ];
    }
}
