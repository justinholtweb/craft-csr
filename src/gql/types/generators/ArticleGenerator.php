<?php

declare(strict_types=1);

namespace justinholtweb\csr\gql\types\generators;

use Craft;
use craft\gql\base\Generator;
use craft\gql\base\GeneratorInterface;
use craft\gql\base\ObjectType;
use craft\gql\base\SingleGeneratorInterface;
use craft\gql\GqlEntityRegistry;
use craft\helpers\Gql as GqlHelper;
use justinholtweb\csr\elements\Article;
use justinholtweb\csr\gql\interfaces\ArticleInterface;
use justinholtweb\csr\gql\types\ArticleType;
use justinholtweb\csr\Plugin;

/**
 * One type, `CsrArticle`: articles share a single field layout, so there is nothing to vary a type
 * by. It carries the layout's custom fields, and exists only when the schema may read articles.
 */
class ArticleGenerator extends Generator implements GeneratorInterface, SingleGeneratorInterface
{
    public static function generateTypes(mixed $context = null): array
    {
        if (!GqlHelper::isSchemaAwareOf(Article::gqlScopesByContext(null))) {
            return [];
        }

        $type = static::generateType(null);

        return [$type->name => $type];
    }

    public static function generateType(mixed $context): ObjectType
    {
        $typeName = Article::gqlTypeName();

        return GqlEntityRegistry::getOrCreate($typeName, fn() => new ArticleType([
            'name' => $typeName,
            'fields' => function() use ($typeName) {
                return Craft::$app->getGql()->prepareFieldDefinitions(
                    array_merge(
                        ArticleInterface::getFieldDefinitions(),
                        self::getContentFields(Plugin::getInstance()->articles->getFieldLayout()),
                    ),
                    $typeName,
                );
            },
        ]));
    }
}
