<?php

declare(strict_types=1);

namespace justinholtweb\csr\services;

use Craft;
use craft\base\Component;
use craft\db\Query;
use craft\helpers\Db;
use justinholtweb\csr\elements\Ticket;
use justinholtweb\csr\models\CannedReply;
use justinholtweb\csr\models\Edition;
use justinholtweb\csr\Plugin;
use justinholtweb\csr\records\CannedReplyRecord;
use justinholtweb\csr\records\Table;

/**
 * Replies somebody has already written, well, once.
 *
 * Database rows rather than project config, because agents write these — and the fourth agent
 * improving the refund explanation on a Tuesday afternoon is not a deployment.
 */
class CannedReplies extends Component
{
    /** @var CannedReply[]|null */
    private ?array $_replies = null;

    /** @return CannedReply[] */
    public function getAllReplies(bool $enabledOnly = false): array
    {
        if ($this->_replies === null) {
            $this->_replies = [];

            if (Edition::allowsCannedReplies(Plugin::getInstance()->isPro())) {
                $rows = (new Query())
                    ->select(['id', 'name', 'shortcut', 'body', 'categoryId', 'teamId', 'enabled', 'useCount', 'sortOrder', 'uid'])
                    ->from([Table::CANNED_REPLIES])
                    ->orderBy(['sortOrder' => SORT_ASC, 'name' => SORT_ASC])
                    ->all();

                foreach ($rows as $row) {
                    $this->_replies[] = new CannedReply($row);
                }
            }
        }

        return $enabledOnly
            ? array_values(array_filter($this->_replies, static fn(CannedReply $reply) => $reply->enabled))
            : $this->_replies;
    }

    /**
     * The replies worth offering on this ticket, most-used first.
     *
     * Scoped by category and team when a reply names one, because a library of eighty replies with
     * no filtering is a library nobody scrolls to the bottom of.
     *
     * @return CannedReply[]
     */
    public function getRepliesFor(Ticket $ticket): array
    {
        $replies = array_filter($this->getAllReplies(true), static function (CannedReply $reply) use ($ticket) {
            if ($reply->categoryId !== null && $reply->categoryId !== $ticket->categoryId) {
                return false;
            }

            return $reply->teamId === null || $reply->teamId === $ticket->teamId;
        });

        usort($replies, static fn(CannedReply $a, CannedReply $b) => $b->useCount <=> $a->useCount);

        return array_values($replies);
    }

    public function getReplyById(?int $id): ?CannedReply
    {
        if ($id === null) {
            return null;
        }

        foreach ($this->getAllReplies() as $reply) {
            if ($reply->id === $id) {
                return $reply;
            }
        }

        return null;
    }

    public function getReplyByShortcut(string $shortcut): ?CannedReply
    {
        $shortcut = ltrim($shortcut, '/');

        foreach ($this->getAllReplies(true) as $reply) {
            if ($reply->shortcut !== null && ltrim($reply->shortcut, '/') === $shortcut) {
                return $reply;
            }
        }

        return null;
    }

    public function saveReply(CannedReply $reply, bool $runValidation = true): bool
    {
        if (!Edition::allowsCannedReplies(Plugin::getInstance()->isPro())) {
            $reply->addError('name', Craft::t('csr', 'Canned replies are a Pro feature.'));

            return false;
        }

        if ($runValidation && !$reply->validate()) {
            return false;
        }

        $record = $reply->id !== null ? CannedReplyRecord::findOne($reply->id) : new CannedReplyRecord();

        if ($record === null) {
            return false;
        }

        $record->name = $reply->name;
        $record->shortcut = $reply->shortcut !== null ? '/' . ltrim($reply->shortcut, '/') : null;
        $record->body = $reply->body;
        $record->categoryId = $reply->categoryId;
        $record->teamId = $reply->teamId;
        $record->enabled = $reply->enabled;
        $record->sortOrder = $reply->sortOrder ?? ((int)((new Query())->from([Table::CANNED_REPLIES])->max('[[sortOrder]]') ?? 0) + 1);

        if (!$record->save(false)) {
            return false;
        }

        $reply->id = (int)$record->id;
        $reply->uid = $record->uid;
        $this->_replies = null;

        return true;
    }

    public function deleteReplyById(int $id): bool
    {
        $deleted = (bool)CannedReplyRecord::findOne($id)?->delete();

        if ($deleted) {
            $this->_replies = null;
        }

        return $deleted;
    }

    /** @param int[] $ids */
    public function reorder(array $ids): bool
    {
        foreach (array_values($ids) as $index => $id) {
            Db::update(Table::CANNED_REPLIES, ['sortOrder' => $index + 1], ['id' => $id], [], false);
        }

        $this->_replies = null;

        return true;
    }

    public function refresh(): void
    {
        $this->_replies = null;
    }
}
