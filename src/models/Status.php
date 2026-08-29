<?php

declare(strict_types=1);

namespace justinholtweb\csr\models;

use craft\base\Model;
use craft\helpers\UrlHelper;
use craft\validators\HandleValidator;
use justinholtweb\csr\enums\StatusKind;

/**
 * A place a ticket can be.
 *
 * The name is the site's — "Waiting on customer", "Snoozed", "With engineering" — and the
 * {@see StatusKind} is the contract CSR reads: is this finished, does the clock run here, does it
 * belong in somebody's queue. That separation is what lets a desk name its own process without
 * every report having to be told what the names mean.
 *
 * Project config, because a support process is configuration: reviewable, deployable, and the same
 * on staging as on production.
 */
class Status extends Model
{
    public ?int $id = null;
    public string $name = '';
    public string $handle = '';
    public string $kind = StatusKind::Open->value;
    public string $color = 'gray';
    public ?string $description = null;
    public bool $isDefault = false;

    /** Email the requester when a ticket lands here. */
    public bool $notifyRequester = false;

    /** The letter to send, as Twig. Empty falls back to the generic status-change message. */
    public ?string $emailSubject = null;
    public ?string $emailBody = null;

    public ?int $sortOrder = null;
    public ?string $uid = null;

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
            [['kind'], 'in', 'range' => array_column(StatusKind::cases(), 'value')],
            [['color'], 'string', 'max' => 16],
            [['sortOrder'], 'integer'],
            [['emailSubject'], 'string', 'max' => 255],
        ];
    }

    public function getKind(): StatusKind
    {
        return StatusKind::fromString($this->kind);
    }

    /**
     * What the kind is called, for a template.
     *
     * Templates cannot write `status.kind.label`: Twig resolves `kind` to the *property* — the
     * bare string this status was saved with — before it would ever consider `getKind()`, and
     * asking a string for its label is a runtime error on the one screen that lists statuses.
     */
    public function getKindLabel(): string
    {
        return $this->getKind()->label();
    }

    public function isOpen(): bool
    {
        return $this->getKind()->isOpen();
    }

    public function isPending(): bool
    {
        return $this->getKind()->pausesClock();
    }

    public function isResolved(): bool
    {
        return $this->getKind() === StatusKind::Resolved;
    }

    public function isClosed(): bool
    {
        return $this->getKind() === StatusKind::Closed;
    }

    public function getCpEditUrl(): string
    {
        return UrlHelper::cpUrl('csr/settings/statuses/' . ($this->id ?? 'new'));
    }

    /** @return array<string, mixed> */
    public function getConfig(): array
    {
        return [
            'name' => $this->name,
            'handle' => $this->handle,
            'kind' => $this->kind,
            'color' => $this->color,
            'description' => $this->description ?: null,
            'isDefault' => $this->isDefault,
            'notifyRequester' => $this->notifyRequester,
            'emailSubject' => $this->emailSubject ?: null,
            'emailBody' => $this->emailBody ?: null,
            'sortOrder' => (int)$this->sortOrder,
        ];
    }
}
