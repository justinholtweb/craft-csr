<?php

declare(strict_types=1);

namespace justinholtweb\csr\gql;

use craft\helpers\Gql as GqlHelper;
use GraphQL\Type\Definition\Type;
use justinholtweb\csr\gql\arguments\ArticleArguments;
use justinholtweb\csr\gql\interfaces\ArticleInterface;
use justinholtweb\csr\gql\resolvers\ArticleResolver;
use justinholtweb\csr\gql\types\CategoryType;
use justinholtweb\csr\models\Category;
use justinholtweb\csr\Plugin;

/**
 * CSR's GraphQL queries, prefixed `csr` so they cannot collide with anybody else's `articles`:
 * `csrArticles`, `csrArticle`, `csrArticleCount`, `csrCategories`, `csrCategory`.
 *
 * Two schema components, both read-only: knowledge base articles, and categories. A schema with
 * neither has none of these queries at all. Tickets are never in GraphQL — the help centre is
 * public content; the desk is not.
 */
class Queries
{
    public const ARTICLES_SCOPE = 'csrArticles.all';
    public const CATEGORIES_SCOPE = 'csrCategories.all';

    /** @return array<string, array<string, mixed>> */
    public static function getQueries(bool $checkToken = true): array
    {
        $queries = [];

        if (!$checkToken || GqlHelper::canSchema(self::ARTICLES_SCOPE)) {
            $queries['csrArticles'] = [
                'type' => Type::listOf(ArticleInterface::getType()),
                'args' => ArticleArguments::getArguments(),
                'resolve' => ArticleResolver::class . '::resolve',
                'description' => 'Public CSR knowledge base articles.',
                'complexity' => GqlHelper::relatedArgumentComplexity(),
            ];
            $queries['csrArticleCount'] = [
                'type' => Type::nonNull(Type::int()),
                'args' => ArticleArguments::getArguments(),
                'resolve' => ArticleResolver::class . '::resolveCount',
                'description' => 'The number of public CSR knowledge base articles.',
                'complexity' => GqlHelper::relatedArgumentComplexity(),
            ];
            $queries['csrArticle'] = [
                'type' => ArticleInterface::getType(),
                'args' => ArticleArguments::getArguments(),
                'resolve' => ArticleResolver::class . '::resolveOne',
                'description' => 'One public CSR knowledge base article.',
                'complexity' => GqlHelper::relatedArgumentComplexity(),
            ];
        }

        if (!$checkToken || GqlHelper::canSchema(self::CATEGORIES_SCOPE)) {
            $queries['csrCategories'] = [
                'type' => Type::nonNull(Type::listOf(Type::nonNull(CategoryType::getType()))),
                'args' => [
                    'topLevel' => [
                        'type' => Type::boolean(),
                        'description' => 'Only categories with no parent; read the rest through `children`.',
                    ],
                ],
                'resolve' => static fn($source, array $args) => self::categories((bool)($args['topLevel'] ?? false)),
                'description' => 'The enabled CSR knowledge base categories.',
            ];
            $queries['csrCategory'] = [
                'type' => CategoryType::getType(),
                'args' => [
                    'id' => ['type' => Type::int()],
                    'handle' => ['type' => Type::string()],
                ],
                'resolve' => static fn($source, array $args) => self::category($args),
                'description' => 'One enabled CSR knowledge base category, by ID or handle.',
            ];
        }

        return $queries;
    }

    /** @return Category[] */
    private static function categories(bool $topLevel): array
    {
        if (!Plugin::getInstance()->getSettings()->kbEnabled) {
            return [];
        }

        return array_values(array_filter(
            Plugin::getInstance()->categories->getAllCategories(),
            static fn(Category $category) => $category->enabled && (!$topLevel || $category->parentId === null),
        ));
    }

    /** @param array<string, mixed> $args */
    private static function category(array $args): ?Category
    {
        if (!Plugin::getInstance()->getSettings()->kbEnabled) {
            return null;
        }

        $categories = Plugin::getInstance()->categories;
        $category = isset($args['id'])
            ? $categories->getCategoryById((int)$args['id'])
            : (isset($args['handle']) ? $categories->getCategoryByHandle((string)$args['handle']) : null);

        return $category !== null && $category->enabled ? $category : null;
    }
}
