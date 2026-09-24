<?php

declare(strict_types=1);

namespace justinholtweb\csr\services;

use Craft;
use craft\base\Component;
use craft\db\Query;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use justinholtweb\csr\elements\Article;
use justinholtweb\csr\models\Category;
use justinholtweb\csr\records\CategoryRecord;
use justinholtweb\csr\records\Table;
use Throwable;

/**
 * The subjects articles are filed under and tickets are raised about.
 *
 * All of them are loaded once per request and kept, because practically every screen in the plugin
 * needs the whole list: the knowledge base index, the support form's dropdown, the article index's
 * sources, the deflection search, the reports. A dozen rows read once beats a lookup per article.
 */
class Categories extends Component
{
    /** @var Category[]|null Keyed by ID, in display order. */
    private ?array $_categories = null;

    // Reading
    // -------------------------------------------------------------------------

    /** @return Category[] */
    public function getAllCategories(): array
    {
        if ($this->_categories !== null) {
            return $this->_categories;
        }

        $rows = (new Query())
            ->select([
                'id', 'parentId', 'name', 'handle', 'description', 'icon', 'color',
                'enabled', 'acceptsTickets', 'teamId', 'sortOrder', 'uid',
            ])
            ->from([Table::CATEGORIES])
            ->orderBy(['sortOrder' => SORT_ASC, 'name' => SORT_ASC])
            ->all();

        $categories = [];

        foreach ($rows as $row) {
            $category = new Category($row);
            $categories[(int)$category->id] = $category;
        }

        return $this->_categories = $categories;
    }

    /**
     * The categories a visitor may be shown, as a tree.
     *
     * @return Category[] Top-level categories, with their children attached.
     */
    public function getTree(bool $enabledOnly = true, bool $ticketsOnly = false): array
    {
        $all = $this->getAllCategories();
        $children = [];
        $roots = [];

        foreach ($all as $category) {
            if ($enabledOnly && !$category->enabled) {
                continue;
            }

            if ($ticketsOnly && !$category->acceptsTickets) {
                continue;
            }

            if ($category->parentId !== null && isset($all[$category->parentId])) {
                $children[$category->parentId][] = $category;
            } else {
                $roots[] = $category;
            }
        }

        foreach ($roots as $root) {
            $root->setChildren($children[(int)$root->id] ?? []);
        }

        return $roots;
    }

    /** @return Category[] */
    public function getChildren(int $parentId): array
    {
        return array_values(array_filter(
            $this->getAllCategories(),
            static fn(Category $category) => $category->parentId === $parentId,
        ));
    }

    public function getCategoryById(?int $id): ?Category
    {
        return $id !== null ? ($this->getAllCategories()[$id] ?? null) : null;
    }

    public function getCategoryByHandle(string $handle): ?Category
    {
        foreach ($this->getAllCategories() as $category) {
            if ($category->handle === $handle) {
                return $category;
            }
        }

        return null;
    }

    public function getCategoryByUid(string $uid): ?Category
    {
        foreach ($this->getAllCategories() as $category) {
            if ($category->uid === $uid) {
                return $category;
            }
        }

        return null;
    }

    /**
     * The category and everything filed beneath it.
     *
     * @return int[]
     */
    public function getIdsWithDescendants(int $id): array
    {
        $ids = [$id];

        foreach ($this->getChildren($id) as $child) {
            $ids[] = (int)$child->id;
        }

        return $ids;
    }

    // Counting
    // -------------------------------------------------------------------------

    /**
     * Article and open-ticket counts for every category, in two queries rather than two per row.
     *
     * @return Category[] The same models, with `articleCount` and `ticketCount` filled in.
     */
    public function getAllWithCounts(?int $siteId = null): array
    {
        $categories = $this->getAllCategories();

        $articleCounts = (new Query())
            ->select(['categoryId', 'total' => 'COUNT(*)'])
            ->from([Table::ARTICLES])
            ->groupBy(['categoryId'])
            ->pairs();

        $ticketCounts = (new Query())
            ->select(['categoryId', 'total' => 'COUNT(*)'])
            ->from([Table::TICKETS])
            ->where(['isSpam' => false])
            ->groupBy(['categoryId'])
            ->pairs();

        foreach ($categories as $category) {
            $category->articleCount = (int)($articleCounts[$category->id] ?? 0);
            $category->ticketCount = (int)($ticketCounts[$category->id] ?? 0);
        }

        return $categories;
    }

    /** How many articles a visitor may actually see in this category, including its children. */
    public function getVisibleArticleCount(Category $category, ?int $siteId = null): int
    {
        return (int)Article::find()
            ->siteId($siteId ?? Craft::$app->getSites()->getCurrentSite()->id)
            ->categoryId($this->getIdsWithDescendants((int)$category->id))
            ->forCurrentUser()
            ->status(Article::STATUS_ENABLED)
            ->count();
    }

    // Writing
    // -------------------------------------------------------------------------

    public function saveCategory(Category $category, bool $runValidation = true): bool
    {
        if ($runValidation && !$category->validate()) {
            return false;
        }

        $isNew = $category->id === null;
        $record = $isNew ? new CategoryRecord() : CategoryRecord::findOne($category->id);

        if ($record === null) {
            return false;
        }

        $record->parentId = $category->parentId;
        $record->name = $category->name;
        $record->handle = $category->handle;
        $record->description = $category->description;
        $record->icon = $category->icon;
        $record->color = $category->color;
        $record->enabled = $category->enabled;
        $record->acceptsTickets = $category->acceptsTickets;
        $record->teamId = $category->teamId;
        $record->sortOrder = $category->sortOrder ?? $this->nextSortOrder();

        if (!$record->save(false)) {
            return false;
        }

        $category->id = (int)$record->id;
        $category->uid = $record->uid;
        $category->sortOrder = $record->sortOrder;
        $this->_categories = null;

        return true;
    }

    /**
     * Delete a category without deleting what was filed under it.
     *
     * The foreign keys are `SET NULL` on purpose: an article is worth more than the decision about
     * where to file it, and a ticket that loses its category is still a ticket somebody is waiting
     * on. Children are promoted to the top level rather than deleted with the parent, for the same
     * reason — nothing here may quietly remove content.
     */
    public function deleteCategoryById(int $id): bool
    {
        $record = CategoryRecord::findOne($id);

        if ($record === null) {
            return false;
        }

        $transaction = Craft::$app->getDb()->beginTransaction();

        try {
            Db::update(Table::CATEGORIES, ['parentId' => null], ['parentId' => $id]);
            $record->delete();
            $transaction->commit();
        } catch (Throwable $exception) {
            $transaction->rollBack();
            Craft::error('Could not delete CSR category ' . $id . ': ' . $exception->getMessage(), 'csr');

            return false;
        }

        $this->_categories = null;

        return true;
    }

    /** @param int[] $ids In the order they should appear. */
    public function reorder(array $ids): bool
    {
        foreach (array_values($ids) as $index => $id) {
            Db::update(Table::CATEGORIES, ['sortOrder' => $index + 1], ['id' => $id]);
        }

        $this->_categories = null;

        return true;
    }

    private function nextSortOrder(): int
    {
        $max = (new Query())->from([Table::CATEGORIES])->max('[[sortOrder]]');

        return (int)$max + 1;
    }

    /**
     * The categories a new install starts with.
     *
     * An empty knowledge base with no categories is a screen with nothing to click, and the first
     * thing anybody does with a help desk is look for the shape of one. These are the four
     * subjects nearly every support desk has, and any of them can be renamed or removed.
     */
    public function createDefaults(): void
    {
        if ($this->getAllCategories() !== []) {
            return;
        }

        $defaults = [
            ['name' => 'Getting Started', 'color' => 'blue'],
            ['name' => 'Account & Billing', 'color' => 'green'],
            ['name' => 'Troubleshooting', 'color' => 'orange'],
            ['name' => 'Frequently Asked Questions', 'color' => 'purple'],
        ];

        foreach ($defaults as $index => $default) {
            $category = new Category([
                'name' => $default['name'],
                'handle' => self::handleFor($default['name']),
                'color' => $default['color'],
                'sortOrder' => $index + 1,
            ]);

            $this->saveCategory($category, false);
        }
    }

    /**
     * A safe handle for a name.
     *
     * `StringHelper::toCamelCase()` is not enough on its own: it keeps punctuation, so
     * "Account & Billing" becomes `account&Billing` — which passes silently through a save made
     * with validation off, and then fails `HandleValidator` the first time somebody opens the
     * category to change its colour.
     */
    public static function handleFor(string $name): string
    {
        $clean = preg_replace('/[^A-Za-z0-9 ]+/', ' ', $name) ?? $name;

        return StringHelper::toCamelCase(trim(preg_replace('/\s+/', ' ', $clean) ?? $clean));
    }

    /** Used by the tickets service when a category is deleted mid-flight. */
    public function clearCaches(): void
    {
        $this->_categories = null;
    }

    /** @return array<string, string> id => name, for a select. Includes an "unfiled" option. */
    public function getOptions(bool $includeBlank = true, bool $ticketsOnly = false): array
    {
        $options = $includeBlank ? ['' => Craft::t('csr', 'Not filed')] : [];

        foreach ($this->getTree(true, $ticketsOnly) as $category) {
            $options[(string)$category->id] = $category->name;

            foreach ($category->getChildren() as $child) {
                $options[(string)$child->id] = '   ' . $child->name;
            }
        }

        return $options;
    }
}
