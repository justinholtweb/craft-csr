<?php

declare(strict_types=1);

namespace justinholtweb\csr\models;

use Craft;
use craft\base\Model;
use craft\elements\User;
use DateTime;

/**
 * One thing somebody said on a ticket.
 *
 * Replies and internal notes are the *same* model and the same table, distinguished by `isNote`.
 * That is deliberate: the ticket screen shows one timeline, and two tables would have to be merged
 * in PHP on every render and would eventually disagree about the order of two things written in
 * the same second.
 *
 * The safety property that matters is the other direction — a note must never reach the customer.
 * That is enforced in the query the portal runs, not in the template that renders it, so a site
 * that writes its own ticket template cannot leak one by forgetting an `if`.
 */
class Reply extends Model
{
    public ?int $id = null;
    public ?int $ticketId = null;
    public ?int $userId = null;
    public ?string $authorName = null;
    public ?string $authorEmail = null;
    public string $body = '';
    public bool $isNote = false;
    public bool $byAgent = false;
    public ?int $cannedReplyId = null;
    public ?string $source = null;
    public ?string $ipAddress = null;
    public ?DateTime $dateCreated = null;

    private ?User $_author = null;
    private bool $_authorLoaded = false;

    /** @var Attachment[]|null */
    private ?array $_attachments = null;

    public function rules(): array
    {
        return [
            [['body'], 'required'],
            [['ticketId'], 'integer'],
            [['authorEmail'], 'email'],
        ];
    }

    public function getAuthor(): ?User
    {
        if (!$this->_authorLoaded) {
            $this->_authorLoaded = true;
            $this->_author = $this->userId !== null
                ? Craft::$app->getUsers()->getUserById($this->userId)
                : null;
        }

        return $this->_author;
    }

    /** Who to show as having written it, whether or not they still have an account. */
    public function getAuthorLabel(): string
    {
        $author = $this->getAuthor();

        if ($author !== null) {
            return $author->getFriendlyName() ?: (string)$author->email;
        }

        return $this->authorName ?: ($this->authorEmail ?: Craft::t('csr', 'Unknown'));
    }

    /** @return Attachment[] */
    public function getAttachments(): array
    {
        return $this->_attachments ?? [];
    }

    /** @param Attachment[] $attachments */
    public function setAttachments(array $attachments): void
    {
        $this->_attachments = $attachments;
    }
}
