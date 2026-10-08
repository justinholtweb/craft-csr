<?php

declare(strict_types=1);

namespace justinholtweb\csr\elements\db;

use Craft;
use craft\elements\db\ElementQuery;
use craft\elements\User;
use craft\helpers\Db;
use justinholtweb\csr\enums\Visibility;
use justinholtweb\csr\models\Category;
use justinholtweb\csr\Plugin;
use justinholtweb\csr\records\Table;
use yii\db\Expression;

/**
 * @method \justinholtweb\csr\elements\Article[] all($db = null)
 * @method \justinholtweb\csr\elements\Article|null one($db = null)
 * @method \justinholtweb\csr\elements\Article|null nth(int $n, $db = null)
 *
 * Every parameter that an element-index source may set is typed `mixed`, not narrowly.
 * Craft builds a source's query with `Craft::configure()`, which assigns *straight to a public
 * property* when one exists rather than calling the setter of the same name — so a param declared
 * `?array` against a source saying `['categoryId' => 5]` throws "Cannot assign int to property"
 * and takes the whole index down, for the one source nobody happened to click. Normalising happens
 * in {@see self::beforePrepare()} instead.
 */
class ArticleQuery extends ElementQuery
{
    /** @var mixed Category IDs, or `false` for "filed under nothing". */
    public mixed $categoryId = null;

    /** @var mixed Category handles, for templates that would rather not look IDs up. */
    public mixed $category = null;

    public mixed $featured = null;

    public mixed $visibility = null;

    public mixed $authorId = null;

    /** @var mixed Articles more readers voted down than up, and enough of them to mean it. */
    public mixed $unhelpful = null;

    /**
     * @var mixed Terms to match against the title, keywords and excerpt.
     *
     * Deliberately *not* Craft's `search()`. This is the candidate retrieval behind
     * {@see \justinholtweb\csr\services\Search}, and it has to work on an install whose search
     * index has never been rebuilt — a knowledge base that silently returns nothing is
     * indistinguishable from a knowledge base with nothing in it, and the failed-search report
     * would then be full of searches that did not really fail.
     */
    public mixed $matching = null;

    /**
     * @var User|false|null The reader the results are for.
     *
     * `false` means an anonymous visitor, which is different from `null` ("do not filter at all").
     * The distinction is the whole point: the control panel index must show restricted articles,
     * and a front-end listing must never leak one.
     */
    public User|false|null $forUser = null;

    protected array $defaultOrderBy = ['csr_articles.sortOrder' => SORT_ASC, 'elements.dateCreated' => SORT_DESC];

    public function categoryId(mixed $value): static
    {
        $this->categoryId = $value;

        return $this;
    }

    public function category(mixed $value): static
    {
        $this->category = $value;

        return $this;
    }

    public function featured(mixed $value = true): static
    {
        $this->featured = $value;

        return $this;
    }

    public function visibility(mixed $value): static
    {
        $this->visibility = $value;

        return $this;
    }

    public function authorId(mixed $value): static
    {
        $this->authorId = $value;

        return $this;
    }

    public function unhelpful(mixed $value = true): static
    {
        $this->unhelpful = $value;

        return $this;
    }

    /** @param string[]|string $value */
    public function matching(mixed $value): static
    {
        $this->matching = $value;

        return $this;
    }

    /**
     * Restrict the results to what this reader may see.
     *
     * Pass `false` for an anonymous visitor. Front-end code should always call this — see the note
     * on {@see Visibility} about knowledge bases leaking through their search index rather than
     * through their pages.
     */
    public function forUser(User|false|null $value): static
    {
        $this->forUser = $value;

        return $this;
    }

    /** The reader is whoever is logged in right now. The usual front-end call. */
    public function forCurrentUser(): static
    {
        return $this->forUser(Craft::$app->getUser()->getIdentity() ?? false);
    }

    protected function beforePrepare(): bool
    {
        $this->joinElementTable('csr_articles');

        $this->query->addSelect([
            'csr_articles.categoryId',
            'csr_articles.authorId',
            'csr_articles.excerpt',
            'csr_articles.keywords',
            'csr_articles.visibility',
            'csr_articles.allowedGroupIds',
            'csr_articles.featured',
            'csr_articles.allowRatings',
            'csr_articles.helpfulCount',
            'csr_articles.unhelpfulCount',
            'csr_articles.viewCount',
            'csr_articles.sortOrder',
        ]);

        $this->applyCategory();

        if ($this->featured !== null) {
            $this->subQuery->andWhere(Db::parseBooleanParam('csr_articles.featured', $this->featured));
        }

        if ($this->visibility !== null) {
            $this->subQuery->andWhere(Db::parseParam('csr_articles.visibility', $this->visibility));
        }

        if ($this->authorId !== null) {
            $this->subQuery->andWhere(Db::parseNumericParam('csr_articles.authorId', $this->authorId));
        }

        if ($this->unhelpful !== null && $this->unhelpful !== false) {
            // Five votes is the point at which a score stops being one person's bad morning.
            $this->subQuery->andWhere([
                'and',
                new Expression('[[csr_articles.helpfulCount]] + [[csr_articles.unhelpfulCount]] >= 5'),
                new Expression('[[csr_articles.unhelpfulCount]] > [[csr_articles.helpfulCount]]'),
            ]);
        }

        $this->applyMatching();
        $this->applyVisibility();

        return parent::beforePrepare();
    }

    /**
     * Any of the terms, anywhere CSR itself owns the text.
     *
     * The title lives on `elements_sites` in Craft 5, not on the element table — a condition
     * written against `elements.title` compiles and matches nothing at all.
     */
    private function applyMatching(): void
    {
        if ($this->matching === null || $this->matching === []) {
            return;
        }

        $terms = array_values(array_filter(array_map(
            'strval',
            is_array($this->matching) ? $this->matching : [$this->matching],
        ), static fn(string $term) => $term !== ''));

        if ($terms === []) {
            return;
        }

        $conditions = ['or'];

        foreach ($terms as $term) {
            $conditions[] = ['like', 'elements_sites.title', $term];
            $conditions[] = ['like', 'csr_articles.keywords', $term];
            $conditions[] = ['like', 'csr_articles.excerpt', $term];
        }

        $this->subQuery->andWhere($conditions);
    }

    /**
     * Category IDs and handles, and the "filed under nothing" case.
     *
     * `false` rather than `null` for uncategorised, because a null param means "do not filter" to
     * every other element query in Craft and quietly returning everything is the worst possible
     * answer to "show me the articles nobody has filed".
     */
    private function applyCategory(): void
    {
        if ($this->category !== null) {
            $handles = is_array($this->category) ? $this->category : [$this->category];
            $ids = [];

            foreach ($handles as $handle) {
                $category = Plugin::getInstance()->categories->getCategoryByHandle((string)$handle);

                if ($category !== null) {
                    $ids[] = (int)$category->id;

                    foreach ($category->getChildren() as $child) {
                        $ids[] = (int)$child->id;
                    }
                }
            }

            // A handle nobody recognises must return nothing, not everything.
            $this->subQuery->andWhere($ids !== [] ? ['csr_articles.categoryId' => $ids] : '0=1');
        }

        if ($this->categoryId === null) {
            return;
        }

        if ($this->categoryId === false || $this->categoryId === ':empty:') {
            $this->subQuery->andWhere(['csr_articles.categoryId' => null]);

            return;
        }

        $this->subQuery->andWhere(Db::parseNumericParam('csr_articles.categoryId', $this->categoryId));
    }

    /**
     * The visibility gate, as SQL.
     *
     * Written here rather than in a template so that a restricted article never reaches a listing,
     * a search result, a deflection suggestion, a feed or a sitemap. The twin of
     * {@see \justinholtweb\csr\elements\Article::isVisibleTo()}, which answers the same question
     * about one element — the two are checked against each other in the suite, because a gate that
     * disagrees with itself is a gate with a hole in it.
     */
    private function applyVisibility(): void
    {
        if ($this->forUser === null) {
            return;
        }

        $public = ['csr_articles.visibility' => Visibility::Public->value];

        if ($this->forUser === false) {
            $this->subQuery->andWhere($public);

            return;
        }

        $user = $this->forUser;

        if ($user->admin) {
            return;
        }

        $groupIds = array_map(static fn($group) => (int)$group->id, $user->getGroups());

        $conditions = ['or', $public, ['csr_articles.visibility' => Visibility::Users->value]];

        // The group case has to be matched inside a JSON list, and no database agrees on how. A
        // `LIKE` against the encoded array is portable, and the false positives it can produce
        // (group 1 matching group 11) are removed by re-checking each row against the element's
        // own answer — which is why the two implementations have to agree.
        foreach ($groupIds as $groupId) {
            $conditions[] = [
                'and',
                ['csr_articles.visibility' => Visibility::Groups->value],
                ['or',
                    ['like', 'csr_articles.allowedGroupIds', '[' . $groupId . ',', false],
                    ['like', 'csr_articles.allowedGroupIds', ',' . $groupId . ',', false],
                    ['like', 'csr_articles.allowedGroupIds', ',' . $groupId . ']', false],
                    ['csr_articles.allowedGroupIds' => '[' . $groupId . ']'],
                ],
            ];
        }

        $this->subQuery->andWhere($conditions);
    }

    /** @return Category[] The categories represented in this result set, for a filter bar. */
    public function categories(): array
    {
        $ids = (clone $this)->select(['csr_articles.categoryId'])->column();
        $categories = [];

        foreach (array_unique(array_filter($ids)) as $id) {
            $category = Plugin::getInstance()->categories->getCategoryById((int)$id);

            if ($category !== null) {
                $categories[] = $category;
            }
        }

        return $categories;
    }

    protected function statusCondition(string $status): mixed
    {
        return parent::statusCondition($status);
    }

    /** So `Table::ARTICLES` and this query cannot drift apart in a typo. */
    public static function tableName(): string
    {
        return Table::ARTICLES;
    }
}
