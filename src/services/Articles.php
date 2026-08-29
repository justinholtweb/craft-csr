<?php

declare(strict_types=1);

namespace justinholtweb\csr\services;

use Craft;
use craft\base\Component;
use craft\db\Query;
use craft\events\ConfigEvent;
use craft\helpers\Db;
use craft\helpers\ProjectConfig as ProjectConfigHelper;
use craft\models\FieldLayout;
use DateTime;
use justinholtweb\csr\elements\Article;
use justinholtweb\csr\models\ArticleSiteSettings;
use justinholtweb\csr\Plugin;
use justinholtweb\csr\records\ArticleRelatedRecord;
use justinholtweb\csr\records\ArticleSiteSettingsRecord;
use justinholtweb\csr\records\Table;
use justinholtweb\csr\records\ViewRecord;
use Throwable;
use yii\db\Expression;

/**
 * Everything about articles that is not the element itself: where they live, what they point at,
 * and how often anybody reads them.
 */
class Articles extends Component
{
    public const CONFIG_SITES_KEY = 'csr.articleSites';
    public const CONFIG_FIELD_LAYOUT_KEY = 'csr.articleFieldLayout';

    /** @var ArticleSiteSettings[]|null Keyed by site ID. */
    private ?array $_siteSettings = null;

    // Per-site settings
    // -------------------------------------------------------------------------

    /** @return ArticleSiteSettings[] Keyed by site ID. */
    public function getAllSiteSettings(): array
    {
        if ($this->_siteSettings !== null) {
            return $this->_siteSettings;
        }

        $this->_siteSettings = [];

        foreach (ArticleSiteSettingsRecord::find()->all() as $record) {
            $this->_siteSettings[(int)$record->siteId] = new ArticleSiteSettings([
                'id' => (int)$record->id,
                'siteId' => (int)$record->siteId,
                'enabledByDefault' => (bool)$record->enabledByDefault,
                'uriFormat' => $record->uriFormat,
                'template' => $record->template,
                'baseUri' => $record->baseUri,
                'uid' => $record->uid,
            ]);
        }

        return $this->_siteSettings;
    }

    public function getSiteSettings(?int $siteId): ?ArticleSiteSettings
    {
        $siteId ??= Craft::$app->getSites()->getCurrentSite()->id;

        return $this->getAllSiteSettings()[$siteId] ?? null;
    }

    /** Where the knowledge base index lives on a site, without a leading or trailing slash. */
    public function getBaseUri(?int $siteId = null): ?string
    {
        $settings = $this->getSiteSettings($siteId);
        $base = $settings?->baseUri ?: Plugin::getInstance()->getSettings()->kbPath;

        return $base !== '' ? trim($base, '/') : null;
    }

    /**
     * @param ArticleSiteSettings[] $allSettings
     *
     * Written one site at a time, at `csr.articleSites.<siteUid>`, rather than as one array at the
     * parent path. Handlers are registered against the `{uid}` path, and setting the parent
     * wholesale does not reliably fire them — the write lands in project config and the mirrored
     * records are never created, which presents as a plugin that saved your settings and then
     * ignored them.
     */
    public function saveSiteSettings(array $allSettings): bool
    {
        $projectConfig = Craft::$app->getProjectConfig();
        $keep = [];

        foreach ($allSettings as $settings) {
            if (!$settings->validate()) {
                return false;
            }

            $site = Craft::$app->getSites()->getSiteById((int)$settings->siteId);

            if ($site === null) {
                continue;
            }

            $keep[$site->uid] = true;
            $projectConfig->set(
                self::CONFIG_SITES_KEY . '.' . $site->uid,
                $settings->getConfig(),
                "Update the CSR knowledge base settings for the “{$site->handle}” site",
            );
        }

        // Sites that were in the config and are not in this payload have had the knowledge base
        // taken away from them.
        foreach (array_keys($projectConfig->get(self::CONFIG_SITES_KEY) ?? []) as $uid) {
            if (!isset($keep[$uid])) {
                $projectConfig->remove(self::CONFIG_SITES_KEY . '.' . $uid, 'Remove a CSR knowledge base site');
                $this->removeSiteSettingsRecord((string)$uid);
            }
        }

        $this->_siteSettings = null;

        return true;
    }

    /**
     * Called directly as well as from the config handler.
     *
     * Project config coalesces changes within one request, so a path added *and* removed in the
     * same run fires no `onRemove` handler at all — the row would be orphaned. Both paths are
     * idempotent; the handler is for the other environments, where the change arrives through
     * `project-config/apply`.
     */
    private function removeSiteSettingsRecord(string $siteUid): void
    {
        $siteId = Db::idByUid(\craft\db\Table::SITES, $siteUid);

        if ($siteId !== null) {
            ArticleSiteSettingsRecord::findOne(['siteId' => $siteId])?->delete();
        }
    }

    public function handleChangedSiteSettings(ConfigEvent $event): void
    {
        $siteUid = $event->tokenMatches[0];
        $data = $event->newValue;

        // Sites arrive in project config too, and may not have been processed yet when this
        // handler runs on a fresh apply.
        ProjectConfigHelper::ensureAllSitesProcessed();

        $siteId = Db::idByUid(\craft\db\Table::SITES, $siteUid);

        if ($siteId === null) {
            return;
        }

        $record = ArticleSiteSettingsRecord::findOne(['siteId' => $siteId]) ?? new ArticleSiteSettingsRecord();
        $record->siteId = $siteId;
        $record->enabledByDefault = (bool)($data['enabledByDefault'] ?? true);
        $record->uriFormat = $data['uriFormat'] ?? null;
        $record->template = $data['template'] ?? null;
        $record->baseUri = $data['baseUri'] ?? null;
        $record->save(false);

        $this->_siteSettings = null;
    }

    public function handleDeletedSiteSettings(ConfigEvent $event): void
    {
        $this->removeSiteSettingsRecord($event->tokenMatches[0]);
        $this->_siteSettings = null;
    }

    /** @return array<string, mixed> */
    public function rebuildSiteSettingsConfig(): array
    {
        $config = [];

        foreach ($this->getAllSiteSettings() as $siteId => $settings) {
            $site = Craft::$app->getSites()->getSiteById($siteId);

            if ($site !== null) {
                $config[$site->uid] = $settings->getConfig();
            }
        }

        return $config;
    }

    /**
     * Give every site a row, so a fresh install has working article URLs immediately.
     *
     * The one decision made on the site's behalf, and it is made because the alternative is an
     * install where articles save fine and have no URLs, which reads as the plugin being broken.
     */
    public function installDefaultSiteSettings(): void
    {
        if ($this->getAllSiteSettings() !== []) {
            return;
        }

        $settings = Plugin::getInstance()->getSettings();
        $all = [];

        foreach (Craft::$app->getSites()->getAllSites() as $site) {
            $all[] = new ArticleSiteSettings([
                'siteId' => (int)$site->id,
                'enabledByDefault' => true,
                'uriFormat' => trim($settings->kbPath, '/') . '/{slug}',
                'template' => null,
                'baseUri' => trim($settings->kbPath, '/'),
            ]);
        }

        $this->saveSiteSettings($all);
    }

    // The field layout
    // -------------------------------------------------------------------------

    public function getFieldLayout(): FieldLayout
    {
        return Craft::$app->getFields()->getLayoutByType(Article::class);
    }

    public function saveFieldLayout(FieldLayout $layout): bool
    {
        $layout->type = Article::class;

        Craft::$app->getProjectConfig()->set(
            self::CONFIG_FIELD_LAYOUT_KEY,
            [$layout->uid => $layout->getConfig()],
            'Update the CSR article field layout',
        );

        return true;
    }

    public function handleChangedFieldLayout(ConfigEvent $event): void
    {
        $data = $event->newValue;
        $fieldsService = Craft::$app->getFields();

        if (empty($data) || empty($config = reset($data))) {
            $fieldsService->deleteLayoutsByType(Article::class);

            return;
        }

        $uid = key($data);
        $layout = FieldLayout::createFromConfig($config);
        $layout->id = $fieldsService->getLayoutByType(Article::class)->id;
        $layout->type = Article::class;
        $layout->uid = $uid;

        $fieldsService->saveLayout($layout, false);
    }

    // Related articles
    // -------------------------------------------------------------------------

    /**
     * What to read next.
     *
     * Hand-picked when an author has picked any, and otherwise the most-read articles in the same
     * category. The fallback matters more than the feature does: nobody fills these in, and an
     * empty "see also" is a dead end at exactly the moment a reader has not found their answer.
     *
     * @return Article[]
     */
    public function getRelated(Article $article, ?int $limit = null): array
    {
        $limit ??= Plugin::getInstance()->getSettings()->relatedCount;
        $explicit = $this->getExplicitRelated($article);

        if (count($explicit) >= $limit) {
            return array_slice($explicit, 0, $limit);
        }

        $exclude = array_map(static fn(Article $related) => (int)$related->id, $explicit);
        $exclude[] = (int)$article->id;

        $query = Article::find()
            ->siteId($article->siteId)
            ->forCurrentUser()
            ->id(array_merge(['not'], $exclude))
            ->limit($limit - count($explicit))
            ->orderBy(['csr_articles.viewCount' => SORT_DESC]);

        if ($article->categoryId !== null) {
            $query->categoryId(Plugin::getInstance()->categories->getIdsWithDescendants($article->categoryId));
        }

        return array_merge($explicit, $query->all());
    }

    /** @return Article[] Only the ones an author chose, in the order they chose them. */
    public function getExplicitRelated(Article $article): array
    {
        if ($article->id === null) {
            return [];
        }

        $ids = (new Query())
            ->select(['relatedId'])
            ->from([Table::ARTICLE_RELATED])
            ->where(['articleId' => $article->id])
            ->orderBy(['sortOrder' => SORT_ASC])
            ->column();

        if ($ids === []) {
            return [];
        }

        /** @var Article[] $articles */
        $articles = Article::find()
            ->siteId($article->siteId)
            ->id($ids)
            ->forCurrentUser()
            ->fixedOrder()
            ->all();

        return $articles;
    }

    /** @param int[] $relatedIds */
    public function saveRelated(int $articleId, array $relatedIds): void
    {
        Db::delete(Table::ARTICLE_RELATED, ['articleId' => $articleId]);

        foreach (array_values($relatedIds) as $index => $relatedId) {
            if ((int)$relatedId === $articleId) {
                continue;
            }

            $record = new ArticleRelatedRecord();
            $record->articleId = $articleId;
            $record->relatedId = (int)$relatedId;
            $record->sortOrder = $index + 1;
            $record->save(false);
        }
    }

    // Counters
    // -------------------------------------------------------------------------

    /**
     * Somebody read it.
     *
     * Two writes: a lifetime counter on the article, which is what the index sorts by, and a row
     * in the daily rollup, which is what the trend chart reads. Both are `UPDATE … + 1` rather
     * than read-modify-write, so two readers arriving in the same millisecond both count.
     */
    public function recordView(Article $article, ?int $siteId = null): void
    {
        if ($article->id === null || !Plugin::getInstance()->getSettings()->trackViews) {
            return;
        }

        $siteId ??= (int)$article->siteId;
        $today = (new DateTime())->format('Y-m-d');
        Db::update(
            Table::ARTICLES,
            ['viewCount' => new Expression('[[viewCount]] + 1')],
            ['id' => $article->id],
            [],
            false,
        );

        // Update-then-insert rather than `Db::upsert()`: upsert's update half would have to
        // accumulate rather than overwrite, and the expression that does that is written
        // differently on MySQL and Postgres. An increment always changes the value, so a row that
        // exists always reports at least one affected row — which is the case where MySQL's
        // "zero rows affected when nothing changed" would otherwise mislead.
        $affected = Db::update(
            Table::VIEWS,
            ['views' => new Expression('[[views]] + 1')],
            ['articleId' => $article->id, 'siteId' => $siteId, 'date' => $today],
            [],
            false,
        );

        if ($affected === 0) {
            try {
                $record = new ViewRecord();
                $record->articleId = (int)$article->id;
                $record->siteId = $siteId;
                $record->date = $today;
                $record->views = 1;
                $record->save(false);
            } catch (Throwable) {
                // Two first-of-the-day readers in the same millisecond: one of them loses the
                // unique index and the other's row is already correct. A page view is not worth
                // an exception on the reader's screen.
                Db::update(
                    Table::VIEWS,
                    ['views' => new Expression('[[views]] + 1')],
                    ['articleId' => $article->id, 'siteId' => $siteId, 'date' => $today],
                    [],
                    false,
                );
            }
        }

        $article->viewCount++;
    }

    /** Recount the helpful/unhelpful columns from the rating rows. Used by the console command. */
    public function recountRatings(?int $articleId = null): int
    {
        $condition = $articleId !== null ? ['articleId' => $articleId] : [];

        $counts = (new Query())
            ->select([
                'articleId',
                'helpful' => 'SUM(CASE WHEN [[helpful]] = 1 THEN 1 ELSE 0 END)',
                'unhelpful' => 'SUM(CASE WHEN [[helpful]] = 0 THEN 1 ELSE 0 END)',
            ])
            ->from([Table::RATINGS])
            ->where($condition)
            ->groupBy(['articleId'])
            ->all();

        $seen = [];

        foreach ($counts as $row) {
            Db::update(Table::ARTICLES, [
                'helpfulCount' => (int)$row['helpful'],
                'unhelpfulCount' => (int)$row['unhelpful'],
            ], ['id' => $row['articleId']]);

            $seen[] = (int)$row['articleId'];
        }

        // Articles whose every rating was deleted have no row in the result above, so they would
        // keep the counts they had. Zero them explicitly.
        $zeroCondition = ['and', ['not', ['helpfulCount' => 0, 'unhelpfulCount' => 0]]];

        if ($seen !== []) {
            $zeroCondition[] = ['not', ['id' => $seen]];
        }

        if ($articleId !== null) {
            $zeroCondition[] = ['id' => $articleId];
        }

        Db::update(Table::ARTICLES, ['helpfulCount' => 0, 'unhelpfulCount' => 0], $zeroCondition);

        return count($counts);
    }

    public function nextSortOrder(?int $categoryId): int
    {
        $max = (new Query())
            ->from([Table::ARTICLES])
            ->where(['categoryId' => $categoryId])
            ->max('[[sortOrder]]');

        return (int)$max + 1;
    }

    /** @param int[] $ids In the order they should appear within their category. */
    public function reorder(array $ids): bool
    {
        $transaction = Craft::$app->getDb()->beginTransaction();

        try {
            foreach (array_values($ids) as $index => $id) {
                Db::update(Table::ARTICLES, ['sortOrder' => $index + 1], ['id' => $id]);
            }

            $transaction->commit();
        } catch (Throwable $exception) {
            $transaction->rollBack();
            Craft::error('Could not reorder CSR articles: ' . $exception->getMessage(), 'csr');

            return false;
        }

        return true;
    }

    // Front-end helpers
    // -------------------------------------------------------------------------

    /**
     * The most-read articles a visitor may see.
     *
     * Over a window rather than all time, because "popular" measured over five years is a list of
     * whatever was written first.
     *
     * @return Article[]
     */
    public function getPopular(?int $siteId = null, ?int $limit = null, ?int $categoryId = null): array
    {
        $settings = Plugin::getInstance()->getSettings();
        $days = max(1, $settings->popularWindowDays);
        $since = (new DateTime())->modify("-{$days} days")->format('Y-m-d');

        $ids = (new Query())
            ->select(['articleId', 'total' => 'SUM([[views]])'])
            ->from([Table::VIEWS])
            ->where(['>=', 'date', $since])
            ->andFilterWhere(['siteId' => $siteId])
            ->groupBy(['articleId'])
            ->orderBy(['total' => SORT_DESC])
            ->limit($limit ?? 5)
            ->column();

        $query = Article::find()
            ->siteId($siteId ?? Craft::$app->getSites()->getCurrentSite()->id)
            ->forCurrentUser()
            ->limit($limit ?? 5);

        if ($categoryId !== null) {
            $query->categoryId(Plugin::getInstance()->categories->getIdsWithDescendants($categoryId));
        }

        // Nothing has been read yet on a new install, and an empty "popular articles" panel on the
        // homepage of a help centre looks broken. Fall back to the lifetime counter.
        if ($ids === []) {
            return $query->orderBy(['csr_articles.viewCount' => SORT_DESC])->all();
        }

        return $query->id($ids)->fixedOrder()->all();
    }

    /** @return Article[] */
    public function getFeatured(?int $siteId = null, ?int $limit = null): array
    {
        return Article::find()
            ->siteId($siteId ?? Craft::$app->getSites()->getCurrentSite()->id)
            ->featured()
            ->forCurrentUser()
            ->limit($limit ?? 6)
            ->all();
    }

    /** Delete the daily view rows nobody will report on again. */
    public function pruneViews(int $days): int
    {
        if ($days <= 0) {
            return 0;
        }

        $cutoff = (new DateTime())->modify("-{$days} days")->format('Y-m-d');

        return Db::delete(Table::VIEWS, ['<', 'date', $cutoff]);
    }

    /** Rows whose article has gone. The foreign keys handle this; the sweep is a belt. */
    public function deleteOrphans(): int
    {
        $articleIds = (new Query())->select(['id'])->from([Table::ARTICLES])->column();

        if ($articleIds === []) {
            return 0;
        }

        return Db::delete(Table::ARTICLE_RELATED, ['not', ['relatedId' => $articleIds]]);
    }
}
