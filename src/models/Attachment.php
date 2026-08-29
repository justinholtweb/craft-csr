<?php

declare(strict_types=1);

namespace justinholtweb\csr\models;

use Craft;
use craft\base\Model;
use craft\elements\Asset;
use craft\helpers\UrlHelper;
use DateTime;

/**
 * A file somebody attached, and the row that remembers it existed.
 *
 * The file itself is a Craft asset in a volume the site chose, which is what makes it manageable,
 * indexable and subject to the site's own storage arrangements. This row exists anyway because the
 * asset may be deleted — by a retention sweep, by somebody tidying a volume — and "the customer
 * attached `error.log`, and it is gone now" is a truer thing for the ticket to say than showing
 * nothing at all.
 *
 * The download always goes through CSR, never through the asset's own URL: a screenshot a stranger
 * uploaded to a support ticket is somebody else's data on a guessable path.
 */
class Attachment extends Model
{
    public ?int $id = null;
    public ?int $ticketId = null;
    public ?int $replyId = null;
    public ?int $assetId = null;
    public string $filename = '';
    public int $size = 0;
    public ?string $kind = null;
    public ?int $uploadedBy = null;
    public ?DateTime $dateCreated = null;

    private ?Asset $_asset = null;
    private bool $_assetLoaded = false;

    public function getAsset(): ?Asset
    {
        if (!$this->_assetLoaded) {
            $this->_assetLoaded = true;

            if ($this->assetId !== null) {
                $asset = Craft::$app->getElements()->getElementById($this->assetId, Asset::class);
                $this->_asset = $asset instanceof Asset ? $asset : null;
            }
        }

        return $this->_asset;
    }

    /** Whether the file is still there. False means the row is a headstone. */
    public function getExists(): bool
    {
        return $this->getAsset() !== null;
    }

    /** A control panel URL that streams the file, for agents. */
    public function getDownloadUrl(): ?string
    {
        return $this->id !== null && $this->getExists()
            ? UrlHelper::cpUrl('csr/tickets/download', ['attachmentId' => $this->id])
            : null;
    }

    /** A site URL that streams the file to the requester, gated on their own ticket reference. */
    public function getPortalDownloadUrl(string $reference): ?string
    {
        return $this->id !== null && $this->getExists()
            ? UrlHelper::siteUrl('csr/portal/download', ['attachmentId' => $this->id, 'csrRef' => $reference])
            : null;
    }

    public function getFormattedSize(): string
    {
        return Craft::$app->getFormatter()->asShortSize($this->size, 1);
    }
}
