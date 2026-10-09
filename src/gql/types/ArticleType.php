<?php

declare(strict_types=1);

namespace justinholtweb\csr\gql\types;

use craft\gql\types\elements\Element;
use GraphQL\Type\Definition\ResolveInfo;
use justinholtweb\csr\elements\Article;
use justinholtweb\csr\enums\Visibility;
use justinholtweb\csr\gql\interfaces\ArticleInterface;

/**
 * The object type for knowledge base articles: `CsrArticle`.
 */
class ArticleType extends Element
{
    public function __construct(array $config)
    {
        $config['interfaces'] = [
            ArticleInterface::getType(),
        ];

        parent::__construct($config);
    }

    protected function resolve(mixed $source, array $arguments, mixed $context, ResolveInfo $resolveInfo): mixed
    {
        /** @var Article $source */
        return match ($resolveInfo->fieldName) {
            'category' => ($category = $source->getCategory()) !== null && $category->enabled ? $category : null,
            // The same public-only rule as the queries: a hand-picked related article that is
            // restricted or disabled is left out, not leaked through its neighbour.
            'relatedArticles' => array_values(array_filter(
                $source->getRelatedArticles(),
                static fn(Article $article) => $article->getVisibility() === Visibility::Public
                    && $article->enabled
                    && $article->getEnabledForSite() !== false,
            )),
            default => parent::resolve($source, $arguments, $context, $resolveInfo),
        };
    }
}
