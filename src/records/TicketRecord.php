<?php

declare(strict_types=1);

namespace justinholtweb\csr\records;

use craft\db\ActiveRecord;
use craft\records\Element;
use yii\db\ActiveQueryInterface;

/**
 * @property int $id
 * @property int $number
 * @property string $reference
 * @property string|null $replyToken
 * @property int|null $statusId
 * @property string $priority
 * @property int|null $categoryId
 * @property int|null $agentId
 * @property int|null $teamId
 * @property int|null $requesterId
 * @property string $requesterEmail
 * @property string|null $requesterName
 * @property int|null $submittedSiteId
 * @property string $source
 * @property string|null $message
 * @property string|null $answers
 * @property int|null $slaPolicyId
 * @property string|null $firstResponseDue
 * @property string|null $resolutionDue
 * @property string|null $firstRespondedAt
 * @property string|null $resolvedAt
 * @property string|null $closedAt
 * @property string|null $lastReplyAt
 * @property string|null $lastReplyBy
 * @property bool $firstResponseBreached
 * @property bool $resolutionBreached
 * @property string|null $escalatedAt
 * @property string|null $clockPausedAt
 * @property int $pausedSeconds
 * @property int $replyCount
 * @property int $agentReplyCount
 * @property int|null $deflectionId
 * @property int|null $pigeonThreadId
 * @property int|null $satisfaction
 * @property string|null $satisfactionComment
 * @property bool $isSpam
 * @property int|null $mergedIntoId
 * @property string|null $ipAddress
 * @property string|null $userAgent
 * @property string|null $submittedAt
 */
class TicketRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return Table::TICKETS;
    }

    public function getElement(): ActiveQueryInterface
    {
        return $this->hasOne(Element::class, ['id' => 'id']);
    }
}
