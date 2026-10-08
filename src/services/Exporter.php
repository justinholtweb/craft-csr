<?php

declare(strict_types=1);

namespace justinholtweb\csr\services;

use Craft;
use craft\base\Component;
use craft\db\Query;
use craft\helpers\Db;
use craft\web\Response;
use DateTime;
use justinholtweb\csr\elements\Ticket;
use justinholtweb\csr\models\Edition;
use justinholtweb\csr\Plugin;
use justinholtweb\csr\records\Table;

/**
 * CSV, streamed.
 *
 * Streamed rather than built in memory because the honest size of a support export is "however
 * many tickets there are", and a desk with four years of history is the one most likely to want
 * one.
 *
 * The trap in Yii worth naming: a callable `Response::$stream` has whatever it **returns** echoed
 * after the body, item by item. A streamer that writes to `php://output` itself must return an
 * empty array, or its return value is appended to the file the browser downloads.
 */
class Exporter extends Component
{
    public function isAvailable(): bool
    {
        return Edition::allowsExport(Plugin::getInstance()->isPro());
    }

    /** @param Ticket[]|\craft\elements\db\ElementQueryInterface $tickets */
    public function streamTickets(mixed $tickets, ?Response $response = null): Response
    {
        $response ??= Craft::$app->getResponse();
        $statuses = Plugin::getInstance()->statuses;
        $categories = Plugin::getInstance()->categories;

        $rows = static function() use ($tickets, $statuses, $categories): void {
            $handle = fopen('php://output', 'wb');

            fputcsv($handle, [
                'Number', 'Subject', 'Status', 'Priority', 'Category', 'Requester', 'Email',
                'Agent', 'Team', 'Source', 'Raised', 'First replied', 'Resolved', 'Closed',
                'Replies', 'First response breached', 'Resolution breached', 'Satisfaction',
            ]);

            $iterator = is_array($tickets) ? $tickets : $tickets->each(100);

            foreach ($iterator as $ticket) {
                /** @var Ticket $ticket */
                fputcsv($handle, [
                    $ticket->number,
                    (string)$ticket->title,
                    (string)$statuses->getStatusById($ticket->statusId)?->name,
                    $ticket->getPriority()->label(),
                    (string)$categories->getCategoryById($ticket->categoryId)?->name,
                    $ticket->getRequesterLabel(),
                    $ticket->requesterEmail,
                    (string)$ticket->getAgent()?->getFriendlyName(),
                    (string)$ticket->getTeam()?->name,
                    $ticket->getSource()->label(),
                    $ticket->submittedAt?->format('Y-m-d H:i:s'),
                    $ticket->firstRespondedAt?->format('Y-m-d H:i:s'),
                    $ticket->resolvedAt?->format('Y-m-d H:i:s'),
                    $ticket->closedAt?->format('Y-m-d H:i:s'),
                    $ticket->replyCount,
                    $ticket->firstResponseBreached ? 'yes' : 'no',
                    $ticket->resolutionBreached ? 'yes' : 'no',
                    $ticket->satisfaction ?? '',
                ]);
            }

            fclose($handle);
        };

        return $this->stream($response, 'csr-tickets-' . date('Y-m-d') . '.csv', $rows);
    }

    /**
     * Every search in the window, so somebody can take the failed ones away and work through them.
     *
     * The point of exporting this rather than only showing it is that the list of things a
     * knowledge base cannot answer is a **work list**, and work lists get assigned to people in
     * spreadsheets.
     */
    public function streamSearches(int $days = 90, ?int $siteId = null, ?Response $response = null): Response
    {
        $response ??= Craft::$app->getResponse();

        $query = (new Query())
            ->select(['normalizedQuery', 'query', 'resultCount', 'clickedArticleId', 'source', 'dateCreated'])
            ->from([Table::SEARCHES])
            ->orderBy(['dateCreated' => SORT_DESC]);

        if ($days > 0) {
            $query->where(['>=', 'dateCreated', Db::prepareDateForDb((new DateTime())->modify("-{$days} days"))]);
        }

        if ($siteId !== null) {
            $query->andWhere(['siteId' => $siteId]);
        }

        $rows = static function() use ($query): void {
            $handle = fopen('php://output', 'wb');
            fputcsv($handle, ['Search', 'As typed', 'Results', 'Opened an article', 'Source', 'When']);

            foreach ($query->each(200) as $row) {
                fputcsv($handle, [
                    $row['normalizedQuery'],
                    $row['query'],
                    $row['resultCount'],
                    $row['clickedArticleId'] !== null ? 'yes' : 'no',
                    $row['source'],
                    $row['dateCreated'],
                ]);
            }

            fclose($handle);
        };

        return $this->stream($response, 'csr-searches-' . date('Y-m-d') . '.csv', $rows);
    }

    /** The "was this helpful?" comments, which are the other half of the work list. */
    public function streamFeedback(int $days = 90, ?Response $response = null): Response
    {
        $response ??= Craft::$app->getResponse();
        $feedback = Plugin::getInstance()->ratings->getFeedback(null, 5000, $days);

        $rows = static function() use ($feedback): void {
            $handle = fopen('php://output', 'wb');
            fputcsv($handle, ['Article', 'Comment', 'When']);

            foreach ($feedback as $row) {
                $article = \justinholtweb\csr\elements\Article::find()
                    ->id((int)$row['articleId'])
                    ->siteId('*')
                    ->unique()
                    ->status(null)
                    ->one();

                fputcsv($handle, [
                    (string)($article->title ?? $row['articleId']),
                    $row['comment'],
                    $row['dateCreated'],
                ]);
            }

            fclose($handle);
        };

        return $this->stream($response, 'csr-feedback-' . date('Y-m-d') . '.csv', $rows);
    }

    private function stream(Response $response, string $filename, callable $write): Response
    {
        $response->format = Response::FORMAT_RAW;
        $response->headers->set('Content-Type', 'text/csv; charset=utf-8');
        $response->setDownloadHeaders($filename, 'text/csv');

        $response->stream = static function() use ($write): array {
            $write();

            // Yii echoes whatever this returns, iterating it item by item — so anything other
            // than an empty array is appended to the file the browser downloads.
            return [];
        };

        return $response;
    }
}
