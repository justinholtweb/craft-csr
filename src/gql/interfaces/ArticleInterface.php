<?php

declare(strict_types=1);

namespace justinholtweb\csr\gql\interfaces;

use Craft;
use craft\gql\GqlEntityRegistry;
use craft\gql\interfaces\Element;
use GraphQL\Type\Definition\InterfaceType;
use GraphQL\Type\Definition\Type;
use justinholtweb\csr\gql\types\CategoryType;
use justinholtweb\csr\gql\types\generators\ArticleGenerator;

/**
 * The GraphQL interface for knowledge base articles.
 *
 * Only what a public help centre shows. No visibility, no allowed groups, no author: the GraphQL
 * door serves **public** articles only (see {@see \justinholtweb\csr\gql\resolvers\ArticleResolver}),
 * so there is nothing about restriction worth exposing, and exposing it would tell a stranger which
 * articles exist behind the login.
 */
class ArticleInterface extends Element
{
    public static function getTypeGenerator(): string
    {
        return ArticleGenerator::class;
    }

    public static function getType(): Type
    {
        if ($type = GqlEntityRegistry::getEntity(self::getName())) {
            return $type;
        }

        $type = GqlEntityRegistry::createEntity(self::getName(), new InterfaceType([
            'name' => static::getName(),
            'fields' => self::class . '::getFieldDefinitions',
            'description' => 'This is the interface implemented by CSR knowledge base articles.',
            'resolveType' => self::class . '::resolveElementTypeName',
        ]));

        ArticleGenerator::generateTypes();

        return $type;
    }

    public static function getName(): string
    {
        return 'CsrArticleInterface';
    }

    public static function getFieldDefinitions(): array
    {
        return Craft::$app->getGql()->prepareFieldDefinitions(array_merge(parent::getFieldDefinitions(), [
            'url' => [
                'name' => 'url',
                'type' => Type::string(),
                'description' => 'The article’s page on this site.',
            ],
            'excerpt' => [
                'name' => 'excerpt',
                'type' => Type::string(),
                'description' => 'The summary shown in listings and search results.',
            ],
            'keywords' => [
                'name' => 'keywords',
                'type' => Type::string(),
                'description' => 'The “Also found by” phrases: words customers use that the article itself does not.',
            ],
            'featured' => [
                'name' => 'featured',
                'type' => Type::nonNull(Type::boolean()),
                'description' => 'Whether the article is featured on the help centre’s front page.',
            ],
            'categoryId' => [
                'name' => 'categoryId',
                'type' => Type::int(),
                'description' => 'The ID of the article’s category.',
            ],
            'category' => [
                'name' => 'category',
                'type' => CategoryType::getType(),
                'description' => 'The article’s category, when it is filed under an enabled one.',
            ],
            'helpfulCount' => [
                'name' => 'helpfulCount',
                'type' => Type::nonNull(Type::int()),
                'description' => 'How many readers said the article helped.',
            ],
            'unhelpfulCount' => [
                'name' => 'unhelpfulCount',
                'type' => Type::nonNull(Type::int()),
                'description' => 'How many readers said it did not.',
            ],
            'relatedArticles' => [
                'name' => 'relatedArticles',
                'type' => Type::listOf(self::getType()),
                'description' => 'What to read next: hand-picked, or the most-read in the same category. Public articles only.',
            ],
        ]), self::getName());
    }
}
