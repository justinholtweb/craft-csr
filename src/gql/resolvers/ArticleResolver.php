<?php

declare(strict_types=1);

namespace justinholtweb\csr\gql\resolvers;

use craft\elements\db\ElementQuery;
use craft\gql\base\ElementResolver;
use craft\helpers\Gql as GqlHelper;
use justinholtweb\csr\elements\Article;
use justinholtweb\csr\elements\db\ArticleQuery;
use justinholtweb\csr\Plugin;
use yii\base\UnknownMethodException;

/**
 * Resolves article queries — public knowledge base content, and nothing else.
 *
 * The gate is applied **here**, after the arguments, rather than left to the schema: a schema
 * permission says "this token may read articles", and restricted articles are articles. So every
 * query is narrowed to `visibility = public`, enabled, not trashed, as an anonymous visitor would
 * see it — `forUser(false)`, never the current user. That also keeps Craft's GraphQL result cache
 * safe: it is keyed by schema and query, not by who is asking, so an answer that depended on the
 * signed-in user would be served to the next person to send the same query.
 */
class ArticleResolver extends ElementResolver
{
    public static function prepareQuery(mixed $source, array $arguments, ?string $fieldName = null): mixed
    {
        $query = $source === null ? Article::find() : $source->$fieldName;

        // Eager-loaded already: nothing left to narrow.
        if (!$query instanceof ElementQuery) {
            return $query;
        }

        // A query that matches nothing rather than an empty collection: `csrArticle` and
        // `csrArticleCount` call `one()` and `count()` on whatever comes back.
        if (!GqlHelper::canSchema(Article::gqlScopesByContext(null)[0]) || !Plugin::getInstance()->getSettings()->kbEnabled) {
            return $query->andWhere('0=1');
        }

        foreach ($arguments as $key => $value) {
            try {
                $query->$key($value);
            } catch (UnknownMethodException $e) {
                if ($value !== null) {
                    throw $e;
                }
            }
        }

        /** @var ArticleQuery $query */
        return $query
            ->forUser(false)
            ->status(Article::STATUS_ENABLED)
            ->trashed(false);
    }
}
