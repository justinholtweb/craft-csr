<?php

declare(strict_types=1);

namespace justinholtweb\csr\models;

use craft\base\Model;
use craft\validators\SiteIdValidator;
use craft\validators\UriFormatValidator;

/**
 * What the knowledge base looks like on one site: whether it exists there, and what URLs it gets.
 *
 * The equivalent of a section's site settings, and it exists for the same reason: a company that
 * runs a `.com` and a `.de` wants German help pages under `hilfe/{slug}`, and a company that runs
 * one site wants to set this once and never think about it again.
 *
 * Unlike a section, **the template is optional**. CSR ships a working knowledge base — index,
 * category, article and search — and falls back to it when a site has not written its own. A help
 * center that requires four templates before it renders anything is a help center nobody finishes
 * installing.
 */
class ArticleSiteSettings extends Model
{
    public ?int $id = null;
    public ?int $siteId = null;
    public bool $enabledByDefault = true;
    public ?string $uriFormat = 'kb/{slug}';
    public ?string $template = null;

    /** Where the index lives on this site, for breadcrumbs and "back to the help center". */
    public ?string $baseUri = 'kb';

    public ?string $uid = null;

    public function rules(): array
    {
        return [
            [['siteId'], SiteIdValidator::class],
            [['uriFormat'], UriFormatValidator::class],
            [['template'], 'string', 'max' => 500],
            [['baseUri'], 'string', 'max' => 500],
            // The index and the articles have to agree, or "back to the help center" is a 404.
            [['baseUri'], 'required', 'when' => fn(self $settings) => (bool)$settings->uriFormat],
        ];
    }

    public function getHasUrls(): bool
    {
        return (bool)$this->uriFormat;
    }

    /** @return array<string, mixed> */
    public function getConfig(): array
    {
        return [
            'enabledByDefault' => $this->enabledByDefault,
            'uriFormat' => $this->uriFormat ?: null,
            'template' => $this->template ?: null,
            'baseUri' => $this->baseUri ? trim($this->baseUri, '/') : null,
        ];
    }
}
