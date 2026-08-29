<?php

declare(strict_types=1);

namespace justinholtweb\csr\elements;

use Craft;
use craft\base\Element;
use craft\elements\db\ElementQueryInterface;
use craft\elements\User;
use craft\helpers\Html;
use craft\helpers\Json;
use craft\helpers\StringHelper;
use craft\helpers\UrlHelper;
use craft\models\FieldLayout;
use justinholtweb\csr\elements\db\ArticleQuery;
use justinholtweb\csr\enums\Visibility;
use justinholtweb\csr\models\ArticleSiteSettings;
use justinholtweb\csr\models\Category;
use justinholtweb\csr\Plugin;
use justinholtweb\csr\records\ArticleRecord;

/**
 * A page of the knowledge base.
 *
 * An element, because everything a documentation site needs is element plumbing Craft already has
 * and nobody should write twice: a URI and a template per site, a field layout so the *body* is
 * the site's own fields rather than CSR's guess at one, drafts and revisions so an answer can be
 * rewritten and the old wording recovered, search, relations, the trash, and an index with
 * exporters and bulk actions.
 *
 * ## What is a column and what is a field
 *
 * A column here is something **CSR itself has to reason about**: which category it is in, whether
 * it is public, what the search should also match it on, whether readers found it useful. Those
 * drive queries, deflection and reports, so they cannot live in a field layout the site is free
 * to remove.
 *
 * Everything else — the body, the screenshots, the "applies to version" field — is the layout's,
 * and CSR has no opinion about it.
 *
 * ## `keywords` is the highest-value field here
 *
 * It holds the phrases the article should be found by *that do not appear in it*: what customers
 * call the thing, as opposed to what the documentation calls it. Nobody searches for "payment
 * instrument reconciliation"; they search for "why was I charged twice". Every knowledge base
 * that works has this column filled in and every one that does not, does not.
 *
 * @property-read Category|null $category
 * @property-read Article[] $relatedArticles
 */
class Article extends Element
{
    public ?int $categoryId = null;
    public ?int $authorId = null;
    public ?string $excerpt = null;
    public ?string $keywords = null;
    public string $visibility = Visibility::Public->value;
    public bool $featured = false;
    public bool $allowRatings = true;
    public int $helpfulCount = 0;
    public int $unhelpfulCount = 0;
    public int $viewCount = 0;
    public ?int $sortOrder = null;

    /** @var int[] User group IDs, when the visibility is `groups`. */
    private array $_allowedGroupIds = [];

    private ?Category $_category = null;

    /** @var Article[]|null */
    private ?array $_related = null;

    /** @var int[]|null Set by the editor; written on save. Null means "nobody touched them". */
    private ?array $_relatedIds = null;

    // Identity
    // -------------------------------------------------------------------------

    public static function displayName(): string
    {
        return Craft::t('csr', 'Article');
    }

    public static function lowerDisplayName(): string
    {
        return Craft::t('csr', 'article');
    }

    public static function pluralDisplayName(): string
    {
        return Craft::t('csr', 'Articles');
    }

    public static function pluralLowerDisplayName(): string
    {
        return Craft::t('csr', 'articles');
    }

    public static function refHandle(): ?string
    {
        return 'csrArticle';
    }

    public static function hasTitles(): bool
    {
        return true;
    }

    public static function hasUris(): bool
    {
        return true;
    }

    public static function hasStatuses(): bool
    {
        return true;
    }

    public static function isLocalized(): bool
    {
        return true;
    }

    /**
     * No.
     *
     * CSR renders its own edit screen rather than Craft's generic element editor, because half of
     * what makes an article an article — the category, the visibility gate, the customer-language
     * keywords, the related pages — has no generic control. Advertising drafts while editing
     * outside the element editor produces draft URLs that lead nowhere.
     */
    public static function hasDrafts(): bool
    {
        return false;
    }

    public static function find(): ElementQueryInterface
    {
        return new ArticleQuery(static::class);
    }

    // Sites and URLs
    // -------------------------------------------------------------------------

    public function getSupportedSites(): array
    {
        $sites = [];

        foreach (Plugin::getInstance()->articles->getAllSiteSettings() as $settings) {
            $sites[] = [
                'siteId' => $settings->siteId,
                'enabledByDefault' => $settings->enabledByDefault,
            ];
        }

        // An install that has never opened the sites screen has no rows yet, and Craft treats an
        // empty array as a fatal misconfiguration mid-save rather than as an empty result.
        return $sites !== [] ? $sites : [Craft::$app->getSites()->getPrimarySite()->id];
    }

    public function getSiteSettings(): ?ArticleSiteSettings
    {
        return Plugin::getInstance()->articles->getSiteSettings((int)$this->siteId);
    }

    public function getUriFormat(): ?string
    {
        return $this->getSiteSettings()?->uriFormat;
    }

    protected function previewTargets(): array
    {
        $settings = $this->getSiteSettings();

        if ($settings === null || !$settings->getHasUrls()) {
            return [];
        }

        return [[
            'label' => Craft::t('app', 'Primary {type} page', ['type' => self::lowerDisplayName()]),
            'urlFormat' => $settings->uriFormat,
        ]];
    }

    /**
     * How the article renders.
     *
     * Falls back to CSR's own template when the site has not named one, which is the difference
     * between a plugin you can install and a plugin you have to finish building. A site that wants
     * its own markup names a template and CSR gets out of the way.
     */
    protected function route(): array|string|null
    {
        $settings = $this->getSiteSettings();

        if ($settings === null || !$settings->getHasUrls()) {
            return null;
        }

        if ($settings->template) {
            return ['templates/render', [
                'template' => $settings->template,
                'variables' => ['article' => $this],
            ]];
        }

        return ['csr/kb/article', ['article' => $this]];
    }

    public function getFieldLayout(): ?FieldLayout
    {
        // One layout, found by element type, the way Craft stores the layout for users and
        // addresses. CSR deliberately has no "article types": the axis along which help pages
        // differ is *subject*, and that is what categories are for. Modelling it twice would mean
        // choosing, at the moment an article is created, which fields it can never have.
        return Craft::$app->getFields()->getLayoutByType(self::class);
    }

    protected function cpEditUrl(): ?string
    {
        return UrlHelper::cpUrl(sprintf('csr/articles/%s', $this->getCanonicalId()));
    }

    public function getPostEditUrl(): ?string
    {
        return UrlHelper::cpUrl('csr/articles');
    }

    // The article
    // -------------------------------------------------------------------------

    public function getCategory(): ?Category
    {
        if ($this->_category === null && $this->categoryId !== null) {
            $this->_category = Plugin::getInstance()->categories->getCategoryById($this->categoryId);
        }

        return $this->_category;
    }

    public function getVisibility(): Visibility
    {
        return Visibility::fromString($this->visibility);
    }

    /** @return int[] */
    public function getAllowedGroupIds(): array
    {
        return $this->_allowedGroupIds;
    }

    /**
     * Accepts the JSON the column holds or an array from the editor.
     *
     * The column-shaped case is mandatory rather than convenient: Craft hands the whole database
     * row to the element's constructor, so a selected column with neither a property nor a setter
     * throws `UnknownPropertyException` from `ElementQuery::createElement()` — and only when the
     * element is read from the database, never when it comes back out of the identity map after a
     * save, which hides it from exactly the tests most likely to be written.
     */
    public function setAllowedGroupIds(mixed $value): void
    {
        if (is_string($value)) {
            $value = Json::decodeIfJson($value);
        }

        $this->_allowedGroupIds = array_values(array_unique(array_map(
            'intval',
            is_array($value) ? $value : [],
        )));
    }

    /**
     * Whether this user may read it.
     *
     * The same question the query answers in bulk. Both exist because a listing needs it as SQL
     * and an article page needs it about one element, and they must never disagree — so the
     * conditions are written once, here and in {@see ArticleQuery::applyVisibility()}, and the
     * suite checks them against each other.
     */
    public function isVisibleTo(?User $user): bool
    {
        return match ($this->getVisibility()) {
            Visibility::Public => true,
            Visibility::Users => $user !== null,
            Visibility::Groups => $user !== null && (
                $user->admin
                || array_intersect($this->_allowedGroupIds, array_map(
                    static fn($group) => (int)$group->id,
                    $user->getGroups(),
                )) !== []
            ),
        };
    }

    // Related articles
    // -------------------------------------------------------------------------

    /**
     * What else to read.
     *
     * Hand-picked when an author has picked any, and otherwise the most-read articles in the same
     * category. The fallback matters more than the feature: nobody fills these in, and an empty
     * "see also" is a dead end at exactly the moment a reader has not found their answer.
     *
     * @return Article[]
     */
    public function getRelatedArticles(?int $limit = null): array
    {
        if ($this->_related === null) {
            $this->_related = $this->id !== null
                ? Plugin::getInstance()->articles->getRelated($this, $limit)
                : [];
        }

        return $limit !== null ? array_slice($this->_related, 0, $limit) : $this->_related;
    }

    /** @return int[] */
    public function getRelatedIds(): array
    {
        return $this->_relatedIds ?? array_map(
            static fn(Article $article) => (int)$article->id,
            Plugin::getInstance()->articles->getExplicitRelated($this),
        );
    }

    /**
     * @param int[]|null $ids Null means the caller had no opinion. A save that does not mention
     *                        the related articles must not unfile them — see the family note about
     *                        `getBodyParam('x', [])` defaulting an absent key to "clear them all".
     */
    public function setRelatedIds(?array $ids): void
    {
        $this->_relatedIds = $ids === null
            ? null
            : array_values(array_filter(array_unique(array_map('intval', $ids)), fn(int $id) => $id !== $this->id));
        $this->_related = null;
    }

    // Ratings
    // -------------------------------------------------------------------------

    public function getRatingCount(): int
    {
        return $this->helpfulCount + $this->unhelpfulCount;
    }

    /** The share of readers who said it helped, or null when nobody has said anything. */
    public function getHelpfulness(): ?float
    {
        $total = $this->getRatingCount();

        return $total > 0 ? (float)($this->helpfulCount / $total) : null;
    }

    /**
     * Whether the score is worth acting on.
     *
     * Three votes is not evidence. An article that says "20% helpful (1 of 5)" sends somebody off
     * to rewrite a page that one person had a bad morning on.
     */
    public function getHasMeaningfulRating(): bool
    {
        return $this->getRatingCount() >= 5;
    }

    // Search
    // -------------------------------------------------------------------------

    /**
     * The body as plain text, for snippets and for scoring a search.
     *
     * Read out of the field layout rather than from a column of CSR's own, because the body *is*
     * the site's fields — which is the entire reason an article is an element.
     */
    public function getPlainTextBody(): string
    {
        $parts = [];

        foreach ($this->getFieldLayout()?->getCustomFields() ?? [] as $field) {
            try {
                $value = $this->getFieldValue($field->handle);
            } catch (\Throwable) {
                continue;
            }

            if (is_string($value)) {
                $parts[] = $value;
            } elseif (is_object($value) && method_exists($value, '__toString')) {
                $parts[] = (string)$value;
            }
        }

        return trim(preg_replace('/\s+/u', ' ', strip_tags(implode(' ', $parts))) ?? '');
    }

    protected static function defineSearchableAttributes(): array
    {
        return ['title', 'excerpt', 'keywords'];
    }

    // Saving
    // -------------------------------------------------------------------------

    protected function defineRules(): array
    {
        $rules = parent::defineRules();

        $rules[] = [['categoryId', 'authorId', 'sortOrder'], 'integer'];
        $rules[] = [['excerpt'], 'string', 'max' => 1000];
        $rules[] = [['keywords'], 'string', 'max' => 2000];
        $rules[] = [['visibility'], 'in', 'range' => array_column(Visibility::cases(), 'value')];
        $rules[] = [['visibility'], 'validateRestriction'];

        return $rules;
    }

    /**
     * A restricted article whose restriction cannot be enforced is worse than a public one.
     *
     * "Specific user groups" with no groups chosen would be visible to nobody at all, including
     * the people it was restricted *to* — which reads as the article having vanished.
     */
    public function validateRestriction(string $attribute): void
    {
        if ($this->getVisibility() === Visibility::Groups && $this->_allowedGroupIds === []) {
            $this->addError($attribute, Craft::t('csr', 'Choose at least one user group, or make the article public.'));
        }
    }

    public function beforeSave(bool $isNew): bool
    {
        if ($isNew) {
            $this->authorId ??= Craft::$app->getUser()->getIdentity()?->id;
        }

        if ($this->sortOrder === null) {
            $this->sortOrder = Plugin::getInstance()->articles->nextSortOrder($this->categoryId);
        }

        return parent::beforeSave($isNew);
    }

    public function afterSave(bool $isNew): void
    {
        // Only the canonical article owns a row: drafts and revisions share the element table but
        // must not overwrite the published article's counters.
        if (!$this->propagating) {
            $record = ArticleRecord::findOne($this->id) ?? new ArticleRecord();

            if ($record->getIsNewRecord()) {
                $record->id = $this->id;
            }

            $record->categoryId = $this->categoryId;
            $record->authorId = $this->authorId;
            $record->excerpt = $this->excerpt;
            $record->keywords = $this->keywords;
            $record->visibility = $this->visibility;
            $record->allowedGroupIds = $this->_allowedGroupIds === [] ? null : Json::encode($this->_allowedGroupIds);
            $record->featured = $this->featured;
            $record->allowRatings = $this->allowRatings;
            $record->sortOrder = $this->sortOrder;

            // The counters are owned by the rating and view services, not by the editor. Reading
            // them off the element and writing them back would lose every vote cast while
            // somebody had the edit screen open.
            if ($record->getIsNewRecord()) {
                $record->helpfulCount = 0;
                $record->unhelpfulCount = 0;
                $record->viewCount = 0;
            }

            $record->save(false);

            if ($this->_relatedIds !== null) {
                Plugin::getInstance()->articles->saveRelated((int)$this->id, $this->_relatedIds);
            }
        }

        parent::afterSave($isNew);
    }

    // Index
    // -------------------------------------------------------------------------

    protected static function defineSources(string $context): array
    {
        $sources = [
            [
                'key' => '*',
                'label' => Craft::t('csr', 'All articles'),
                'defaultSort' => ['title', 'asc'],
            ],
            [
                'key' => 'featured',
                'label' => Craft::t('csr', 'Featured'),
                'criteria' => ['featured' => true],
                'defaultSort' => ['title', 'asc'],
            ],
            [
                'key' => 'uncategorized',
                'label' => Craft::t('csr', 'Not filed'),
                // `false` rather than `null`: a null here means "do not filter", which is the
                // opposite of what this source is for. The query maps false to `IS NULL`.
                'categoryId' => false,
                'defaultSort' => ['title', 'asc'],
            ],
            [
                'key' => 'unhelpful',
                'label' => Craft::t('csr', 'Rated unhelpful'),
                'criteria' => ['unhelpful' => true],
                'defaultSort' => ['csr_articles.unhelpfulCount', 'desc'],
            ],
        ];

        $categories = Plugin::getInstance()->categories->getAllCategories();

        if ($categories !== []) {
            $sources[] = ['heading' => Craft::t('csr', 'Category')];

            foreach ($categories as $category) {
                if (!$category->getIsTopLevel()) {
                    continue;
                }

                $childIds = array_map(static fn(Category $child) => (int)$child->id, $category->getChildren());

                $sources[] = [
                    'key' => 'category:' . $category->uid,
                    'label' => $category->name,
                    // A parent shows everything filed under it, including its children's — which
                    // is what somebody clicking a parent category means.
                    'criteria' => ['categoryId' => array_merge([(int)$category->id], $childIds)],
                    'data' => ['handle' => $category->handle],
                    'defaultSort' => ['csr_articles.sortOrder', 'asc'],
                ];
            }
        }

        return $sources;
    }

    protected static function defineTableAttributes(): array
    {
        return [
            'category' => ['label' => Craft::t('csr', 'Category')],
            'helpfulness' => ['label' => Craft::t('csr', 'Helpful')],
            'viewCount' => ['label' => Craft::t('csr', 'Views')],
            'visibility' => ['label' => Craft::t('csr', 'Visibility')],
            'featured' => ['label' => Craft::t('csr', 'Featured')],
            'author' => ['label' => Craft::t('csr', 'Author')],
            'link' => ['label' => Craft::t('app', 'Link')],
            'dateUpdated' => ['label' => Craft::t('app', 'Updated')],
        ];
    }

    protected static function defineDefaultTableAttributes(string $source): array
    {
        return ['category', 'helpfulness', 'viewCount', 'dateUpdated'];
    }

    protected static function defineSortOptions(): array
    {
        return [
            'title' => Craft::t('app', 'Title'),
            'csr_articles.viewCount' => Craft::t('csr', 'Views'),
            'csr_articles.helpfulCount' => Craft::t('csr', 'Helpful votes'),
            'csr_articles.unhelpfulCount' => Craft::t('csr', 'Unhelpful votes'),
            'csr_articles.sortOrder' => Craft::t('csr', 'Order in category'),
            [
                'label' => Craft::t('app', 'Date Updated'),
                'orderBy' => 'elements.dateUpdated',
                'attribute' => 'dateUpdated',
                'defaultDir' => 'desc',
            ],
        ];
    }

    protected function attributeHtml(string $attribute): string
    {
        return match ($attribute) {
            'category' => $this->categoryHtml(),
            'helpfulness' => $this->helpfulnessHtml(),
            'viewCount' => Craft::$app->getFormatter()->asInteger($this->viewCount),
            'visibility' => $this->getVisibility() === Visibility::Public
                ? Html::tag('span', '—', ['class' => 'light'])
                : Html::tag('span', $this->getVisibility()->label(), ['class' => 'status-label']),
            'featured' => $this->featured ? '★' : '',
            'author' => Html::encode((string)($this->authorId !== null
                ? Craft::$app->getUsers()->getUserById($this->authorId)?->getFriendlyName()
                : '')),
            default => parent::attributeHtml($attribute),
        };
    }

    private function categoryHtml(): string
    {
        $category = $this->getCategory();

        return $category !== null
            ? Html::a(Html::encode($category->name), $category->getCpEditUrl())
            : Html::tag('span', Craft::t('csr', 'Not filed'), ['class' => 'light']);
    }

    private function helpfulnessHtml(): string
    {
        $helpfulness = $this->getHelpfulness();

        if ($helpfulness === null) {
            return Html::tag('span', '—', ['class' => 'light']);
        }

        $percent = Craft::$app->getFormatter()->asPercent($helpfulness, 0);
        $label = Craft::t('csr', '{percent} of {n, plural, =1{1 vote} other{# votes}}', [
            'percent' => $percent,
            'n' => $this->getRatingCount(),
        ]);

        // Below half means more readers said it did not help than said it did — which is the one
        // number in this index somebody should act on.
        $class = match (true) {
            !$this->getHasMeaningfulRating() => 'light',
            $helpfulness < 0.5 => 'error',
            default => '',
        };

        return Html::tag('span', $label, $class !== '' ? ['class' => $class] : []);
    }

    // Permissions
    // -------------------------------------------------------------------------

    public function canView(User $user): bool
    {
        return $user->can(Plugin::PERMISSION_VIEW_ARTICLES);
    }

    public function canSave(User $user): bool
    {
        return $user->can(Plugin::PERMISSION_MANAGE_ARTICLES);
    }

    public function canDuplicate(User $user): bool
    {
        return $user->can(Plugin::PERMISSION_MANAGE_ARTICLES);
    }

    public function canDelete(User $user): bool
    {
        return $user->can(Plugin::PERMISSION_DELETE_ARTICLES);
    }

    /** Used by the search scorer, and by anything that wants a stable short label. */
    public function getSlugOrTitle(): string
    {
        return $this->slug ?: StringHelper::toKebabCase((string)$this->title);
    }
}
