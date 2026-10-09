<?php

declare(strict_types=1);

namespace justinholtweb\csr\gql\arguments;

use Craft;
use craft\gql\base\ElementArguments;
use craft\gql\types\QueryArgument;
use GraphQL\Type\Definition\Type;
use justinholtweb\csr\elements\Article;

/**
 * Arguments for `csrArticles`, `csrArticle` and `csrArticleCount`.
 *
 * Nothing here can reach a restricted article: there is deliberately no `visibility` argument, and
 * the resolver applies the public-only gate after every argument has been applied.
 */
class ArticleArguments extends ElementArguments
{
    public static function getArguments(): array
    {
        return array_merge(parent::getArguments(), self::getContentArguments(), [
            'category' => [
                'name' => 'category',
                'type' => Type::listOf(Type::string()),
                'description' => 'Narrows the results to articles in the given category handle(s), children included.',
            ],
            'categoryId' => [
                'name' => 'categoryId',
                'type' => Type::listOf(QueryArgument::getType()),
                'description' => 'Narrows the results to articles in the given category ID(s).',
            ],
            'featured' => [
                'name' => 'featured',
                'type' => Type::boolean(),
                'description' => 'Only featured (or only unfeatured) articles.',
            ],
        ]);
    }

    public static function getContentArguments(): array
    {
        return array_merge(
            parent::getContentArguments(),
            Craft::$app->getGql()->getContentArguments([new Article()], Article::class),
        );
    }
}
