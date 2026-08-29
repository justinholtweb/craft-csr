<?php

declare(strict_types=1);

namespace justinholtweb\csr\controllers;

use Craft;
use craft\web\Controller;
use justinholtweb\csr\models\Edition;
use justinholtweb\csr\Plugin;
use yii\web\ForbiddenHttpException;
use yii\web\Response;

/**
 * The reports.
 *
 * One of these is the reason the plugin exists: **searches that came back with nothing**. A
 * knowledge base's most valuable page is the one it does not have yet, and that report is the only
 * place its name is ever written down.
 *
 * Every screen takes a window and a site, because "all time" is not a question anybody asks about
 * a support desk — it is a question about the history of the company.
 */
class ReportsController extends Controller
{
    private const WINDOWS = [7, 30, 90, 365];

    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requireCpRequest();
        $this->requirePermission(Plugin::PERMISSION_VIEW_REPORTS);

        return true;
    }

    public function actionIndex(): Response
    {
        $plugin = Plugin::getInstance();
        [$days, $siteId] = $this->window();

        return $this->renderTemplate('csr/reports/_index', $this->common($days, $siteId) + [
            'searchTotals' => $plugin->analytics->getSearchTotals($days, $siteId),
            'failed' => $plugin->analytics->getFailedSearches($days, 5, $siteId),
            'desk' => $plugin->analytics->getDeskSummary($days, $siteId),
            'deflection' => $plugin->analytics->getDeflectionValue($days, $siteId),
            'ratings' => $plugin->ratings->getTotals($days),
            'byCategory' => $plugin->analytics->getTicketsByCategory($days),
        ]);
    }

    /** The one that pays for the licence. */
    public function actionSearches(): Response
    {
        $plugin = Plugin::getInstance();
        [$days, $siteId] = $this->window();

        return $this->renderTemplate('csr/reports/_searches', $this->common($days, $siteId) + [
            'totals' => $plugin->analytics->getSearchTotals($days, $siteId),
            'top' => $plugin->analytics->getTopSearches($days, 50, $siteId),
            'failed' => $plugin->analytics->getFailedSearches($days, 50, $siteId),
            'unclicked' => $plugin->analytics->getUnclickedSearches($days, 50, $siteId),
            'available' => Edition::allowsFailedSearchAnalytics($plugin->isPro()),
        ]);
    }

    public function actionArticles(): Response
    {
        $plugin = Plugin::getInstance();
        [$days, $siteId] = $this->window();

        return $this->renderTemplate('csr/reports/_articles', $this->common($days, $siteId) + [
            'performance' => $plugin->analytics->getArticlePerformance($days, 50, $siteId),
            'unhelpful' => $plugin->analytics->getUnhelpfulArticles(15, $siteId),
            'unread' => $plugin->analytics->getUnreadArticles(max($days, 90), 25, $siteId),
            'feedback' => $plugin->ratings->getFeedback(null, 50, $days),
            'trend' => $plugin->analytics->getViewTrend($days, null, $siteId),
            'available' => Edition::allowsArticleAnalytics($plugin->isPro()),
        ]);
    }

    public function actionDesk(): Response
    {
        $plugin = Plugin::getInstance();
        [$days, $siteId] = $this->window();

        return $this->renderTemplate('csr/reports/_desk', $this->common($days, $siteId) + [
            'summary' => $plugin->analytics->getDeskSummary($days, $siteId),
            'workload' => $plugin->analytics->getAgentWorkload($days),
            'byCategory' => $plugin->analytics->getTicketsByCategory($days),
            'trend' => $plugin->analytics->getTicketTrend($days, $siteId),
            'available' => Edition::allowsDeskReports($plugin->isPro()),
        ]);
    }

    public function actionDeflection(): Response
    {
        $plugin = Plugin::getInstance();
        [$days, $siteId] = $this->window();

        return $this->renderTemplate('csr/reports/_deflection', $this->common($days, $siteId) + [
            'stats' => $plugin->deflection->getStats($days, $siteId),
            'top' => $plugin->deflection->getTopDeflectors(15, $days),
            'value' => $plugin->analytics->getDeflectionValue($days, $siteId),
            'available' => Edition::allowsDeflectionAnalytics($plugin->isPro()),
        ]);
    }

    public function actionExportSearches(): Response
    {
        $this->requirePermission(Plugin::PERMISSION_EXPORT);

        $plugin = Plugin::getInstance();

        if (!$plugin->exporter->isAvailable()) {
            throw new ForbiddenHttpException('Exporting is a Pro feature.');
        }

        [$days, $siteId] = $this->window();

        return $plugin->exporter->streamSearches($days, $siteId);
    }

    public function actionExportFeedback(): Response
    {
        $this->requirePermission(Plugin::PERMISSION_EXPORT);

        $plugin = Plugin::getInstance();

        if (!$plugin->exporter->isAvailable()) {
            throw new ForbiddenHttpException('Exporting is a Pro feature.');
        }

        [$days] = $this->window();

        return $plugin->exporter->streamFeedback($days);
    }

    /** @return array{0: int, 1: int|null} */
    private function window(): array
    {
        $request = Craft::$app->getRequest();
        $days = (int)$request->getQueryParam('days', 30);

        // Clamped to the offered windows so a hand-edited URL cannot ask for a group-by over four
        // years of rows on a desk that has them.
        if (!in_array($days, self::WINDOWS, true)) {
            $days = 30;
        }

        $siteId = $request->getQueryParam('siteId');

        return [$days, $siteId !== null && $siteId !== '' ? (int)$siteId : null];
    }

    /** @return array<string, mixed> */
    private function common(int $days, ?int $siteId): array
    {
        $plugin = Plugin::getInstance();

        return [
            'days' => $days,
            'siteId' => $siteId,
            'windows' => self::WINDOWS,
            'sites' => Craft::$app->getSites()->getAllSites(),
            'isPro' => $plugin->isPro(),
            'canExport' => Craft::$app->getUser()->checkPermission(Plugin::PERMISSION_EXPORT)
                && $plugin->exporter->isAvailable(),
        ];
    }
}
