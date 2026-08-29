<?php

declare(strict_types=1);

namespace justinholtweb\csr\models;

use Craft;
use craft\base\Model;
use craft\elements\User;
use DateTime;

/**
 * One reader's answer to "was this helpful?".
 *
 * Unique per article per visitor, and **updatable**: somebody who says no, reads on, and finds the
 * answer further down should be able to change their mind, and an article whose score can only go
 * down is measuring impatience rather than usefulness.
 */
class Rating extends Model
{
    public ?int $id = null;
    public ?int $articleId = null;
    public ?int $siteId = null;
    public ?int $userId = null;
    public bool $helpful = true;

    /** Only asked after a "no", which is when the answer is worth having. */
    public ?string $comment = null;

    public string $visitorKey = '';
    public ?DateTime $dateCreated = null;

    public function rules(): array
    {
        return [
            [['articleId', 'siteId'], 'required'],
            [['articleId', 'siteId', 'userId'], 'integer'],
            [['comment'], 'string', 'max' => 2000],
        ];
    }

    public function getUser(): ?User
    {
        return $this->userId !== null ? Craft::$app->getUsers()->getUserById($this->userId) : null;
    }
}
