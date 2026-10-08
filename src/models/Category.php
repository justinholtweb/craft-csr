<?php

declare(strict_types=1);

namespace justinholtweb\csr\models;

use Craft;
use craft\base\Model;
use craft\helpers\Html;
use craft\helpers\UrlHelper;
use craft\validators\HandleValidator;
use craft\validators\UniqueValidator;
use justinholtweb\csr\Plugin;
use justinholtweb\csr\records\CategoryRecord;

/**
 * One subject, read from both ends.
 *
 * A category is the only thing in CSR that the knowledge base and the desk genuinely share, and
 * that sharing is the whole design: an article filed under Billing is what CSR offers somebody
 * who is about to raise a Billing ticket. Two parallel taxonomies — one for documentation, one for
 * routing — would need a mapping between them, and the mapping is the entire value.
 *
 * Categories are **database rows, not project config**, for the same reason Craft's categories are
 * not: adding "Returns" because the company started accepting returns is content, and the person
 * doing it does not deploy.
 *
 * @property-read Category|null $parent
 * @property-read Category[] $children
 */
class Category extends Model
{
    public ?int $id = null;
    public ?int $parentId = null;
    public string $name = '';
    public string $handle = '';
    public ?string $description = null;
    public ?string $icon = null;
    public string $color = 'blue';
    public bool $enabled = true;

    /** Whether a ticket may be filed under it. Some subjects are documentation only. */
    public bool $acceptsTickets = true;

    /** The team this category's tickets are routed to. Pro. */
    public ?int $teamId = null;

    public ?int $sortOrder = null;
    public ?string $uid = null;

    /** @var int|null Filled by the service when it counts, so the index does not query per row. */
    public ?int $articleCount = null;

    /** @var int|null As above, for the open tickets filed under it. */
    public ?int $ticketCount = null;

    private ?Category $_parent = null;

    /** @var Category[]|null */
    private ?array $_children = null;

    public function __toString(): string
    {
        return $this->name;
    }

    public function rules(): array
    {
        return [
            [['name', 'handle'], 'required'],
            [['name', 'handle'], 'string', 'max' => 255],
            [['handle'], HandleValidator::class],
            // Two rules, not one: a single `UniqueValidator` naming both attributes checks them
            // as a *combined* key, so "same handle, different name" passes validation and then
            // fails on the unique index — an integrity exception instead of a form error.
            [
                ['handle'],
                UniqueValidator::class,
                'targetClass' => CategoryRecord::class,
                'targetAttribute' => 'handle',
                'filter' => fn($query) => $this->id !== null ? $query->andWhere(['not', ['id' => $this->id]]) : $query,
            ],
            [
                ['name'],
                UniqueValidator::class,
                'targetClass' => CategoryRecord::class,
                'targetAttribute' => 'name',
                'filter' => fn($query) => $this->id !== null ? $query->andWhere(['not', ['id' => $this->id]]) : $query,
            ],
            [['color'], 'string', 'max' => 16],
            [['parentId', 'sortOrder', 'teamId'], 'integer'],
            // A category cannot be its own parent, and the tree is deliberately one level deep:
            // a knowledge base whose sidebar needs scrolling to reach a leaf is a knowledge base
            // nobody browses.
            [['parentId'], 'validateParent'],
        ];
    }

    public function validateParent(string $attribute): void
    {
        if ($this->parentId === null) {
            return;
        }

        if ($this->parentId === $this->id) {
            $this->addError($attribute, Craft::t('csr', 'A category cannot be filed under itself.'));

            return;
        }

        $parent = Plugin::getInstance()->categories->getCategoryById($this->parentId);

        if ($parent === null) {
            $this->addError($attribute, Craft::t('csr', 'That category no longer exists.'));
        } elseif ($parent->parentId !== null) {
            $this->addError($attribute, Craft::t('csr', 'Categories only nest one level deep.'));
        }
    }

    public function getParent(): ?Category
    {
        if ($this->_parent === null && $this->parentId !== null) {
            $this->_parent = Plugin::getInstance()->categories->getCategoryById($this->parentId);
        }

        return $this->_parent;
    }

    /** @return Category[] */
    public function getChildren(): array
    {
        return $this->_children ??= $this->id !== null
            ? Plugin::getInstance()->categories->getChildren($this->id)
            : [];
    }

    /** @param Category[] $children */
    public function setChildren(array $children): void
    {
        $this->_children = $children;
    }

    public function getIsTopLevel(): bool
    {
        return $this->parentId === null;
    }

    /**
     * The icon, safe to print.
     *
     * An author may paste an inline SVG here, which is markup CSR has to render rather than
     * escape — so it is purified rather than trusted, and anything that is not an SVG is treated
     * as text (which is what an emoji is).
     */
    public function getIconHtml(): string
    {
        $icon = trim((string)$this->icon);

        if ($icon === '') {
            return '';
        }

        if (!str_starts_with($icon, '<')) {
            return Html::encode($icon);
        }

        return Html::svg($icon, sanitize: true, namespace: false);
    }

    /** Where this category lives on the front end, on the given site. */
    public function getUrl(?int $siteId = null): ?string
    {
        // Built from the same base the route is registered from, `<base>/category/<handle>` —
        // see Plugin::registerSiteRoutes(). Anything else is a link to a 404.
        $plugin = Plugin::getInstance();
        $base = $plugin->articles->getBaseUri($siteId);

        return $plugin->getSettings()->kbEnabled && $base !== null
            ? UrlHelper::siteUrl($base . '/category/' . $this->handle, siteId: $siteId)
            : null;
    }

    /**
     * The team tickets filed here go to: this category's own, or its parent's when it has none,
     * so routing "Billing" routes "Billing › Refunds" too.
     */
    public function getRoutedTeamId(): ?int
    {
        return $this->teamId ?? $this->getParent()?->teamId;
    }

    public function getCpEditUrl(): string
    {
        return UrlHelper::cpUrl('csr/categories/' . ($this->id ?? 'new'));
    }
}
