<?php

declare(strict_types=1);

namespace justinholtweb\csr\models;

use craft\base\Model;
use craft\helpers\Html;
use craft\helpers\StringHelper;
use justinholtweb\csr\elements\Article;

/**
 * One article, and why it came back.
 *
 * The score is carried out of the search rather than recomputed, because the deflection panel and
 * the search results page both need to decide whether a match is *good enough to act on* — showing
 * somebody three weak matches before they are allowed to write in is worse than showing none.
 */
class SearchResult extends Model
{
    public Article $article;

    public float $score = 0.0;

    /** @var array<int, string> Which terms matched, for highlighting and for explaining. */
    public array $matchedTerms = [];

    /** @var array<int, string> Where they matched: title, keywords, excerpt, body, category. */
    public array $matchedIn = [];

    public function __construct(Article $article, array $config = [])
    {
        $this->article = $article;

        parent::__construct($config);
    }

    /**
     * A sentence of the article with the search terms in it, or the start of it if none matched.
     *
     * @param int $length
     */
    public function getSnippet(int $length = 180): string
    {
        $text = trim((string)($this->article->excerpt ?: $this->article->getPlainTextBody()));

        if ($text === '') {
            return '';
        }

        $position = 0;

        foreach ($this->matchedTerms as $term) {
            $found = StringHelper::indexOf(StringHelper::toLowerCase($text), StringHelper::toLowerCase($term));

            if ($found !== false && $found > 0) {
                // Back up to the start of the sentence so the snippet does not begin mid-word.
                $position = max(0, $found - 40);
                break;
            }
        }

        $snippet = mb_substr($text, $position, $length);

        if ($position > 0) {
            $snippet = '…' . ltrim($snippet);
        }

        if (mb_strlen($text) > $position + $length) {
            $snippet = rtrim($snippet) . '…';
        }

        return $snippet;
    }

    /** The snippet with the matched terms wrapped in `<mark>`. */
    public function getHighlightedSnippet(int $length = 180): string
    {
        $snippet = Html::encode($this->getSnippet($length));

        foreach ($this->matchedTerms as $term) {
            if (mb_strlen($term) < 3) {
                continue;
            }

            $snippet = preg_replace(
                '/(' . preg_quote(Html::encode($term), '/') . ')/iu',
                '<mark>$1</mark>',
                $snippet,
            ) ?? $snippet;
        }

        return $snippet;
    }

    /** Whether this match is strong enough to put in front of somebody instead of a form. */
    public function getIsConfident(): bool
    {
        return $this->score >= 10.0;
    }
}
