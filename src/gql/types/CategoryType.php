<?php

declare(strict_types=1);

namespace justinholtweb\csr\gql\types;

use Craft;
use craft\gql\GqlEntityRegistry;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;
use justinholtweb\csr\elements\Article;
use justinholtweb\csr\models\Category;
use justinholtweb\csr\Plugin;

/**
 * A knowledge base category: `CsrCategory`.
 *
 * Categories are CSR models rather than elements — the one taxonomy articles are filed under and
 * tickets are raised about — so this is a plain object type. Only enabled categories are ever
 * returned, and `articleCount` counts **public** articles, the same ones the article queries can
 * see; a count that included restricted articles would say how many exist behind the login.
 */
class CategoryType
{
    public static function getName(): string
    {
        return 'CsrCategory';
    }

    public static function getType(): Type
    {
        return GqlEntityRegistry::getOrCreate(self::getName(), fn() => new ObjectType([
            'name' => self::getName(),
            'description' => 'A CSR knowledge base category.',
            'fields' => fn() => [
                'id' => ['type' => Type::nonNull(Type::int())],
                'uid' => ['type' => Type::string()],
                'name' => ['type' => Type::nonNull(Type::string())],
                'handle' => ['type' => Type::nonNull(Type::string())],
                'description' => ['type' => Type::string()],
                'icon' => ['type' => Type::string(), 'description' => 'The icon’s name, as chosen in the control panel.'],
                'color' => ['type' => Type::string()],
                'parentId' => ['type' => Type::int()],
                'parent' => [
                    'type' => self::getType(),
                    'resolve' => static fn(Category $category) => ($parent = $category->getParent()) !== null && $parent->enabled ? $parent : null,
                ],
                'children' => [
                    'type' => Type::nonNull(Type::listOf(Type::nonNull(self::getType()))),
                    'resolve' => static fn(Category $category) => array_values(array_filter(
                        Plugin::getInstance()->categories->getChildren((int)$category->id),
                        static fn(Category $child) => $child->enabled,
                    )),
                ],
                'url' => [
                    'type' => Type::string(),
                    'description' => 'The category’s page in the help centre.',
                    'args' => ['siteId' => ['type' => Type::int()]],
                    'resolve' => static fn(Category $category, array $args) => $category->getUrl(isset($args['siteId']) ? (int)$args['siteId'] : null),
                ],
                'articleCount' => [
                    'type' => Type::nonNull(Type::int()),
                    'description' => 'Public, enabled articles in this category and its children.',
                    'args' => ['siteId' => ['type' => Type::int()]],
                    'resolve' => static fn(Category $category, array $args) => (int)Article::find()
                        ->siteId(isset($args['siteId']) ? (int)$args['siteId'] : Craft::$app->getSites()->getCurrentSite()->id)
                        ->categoryId(Plugin::getInstance()->categories->getIdsWithDescendants((int)$category->id))
                        ->forUser(false)
                        ->status(Article::STATUS_ENABLED)
                        ->count(),
                ],
            ],
        ]));
    }
}
