<?php

declare(strict_types=1);

namespace justinholtweb\csr\models;

use Craft;
use craft\base\Model;
use craft\elements\User;
use craft\helpers\Json;
use DateTime;
use justinholtweb\csr\enums\EventType;

/**
 * Something that happened to a ticket, written down at the moment it happened.
 *
 * Append-only, and never reconstructed. A `statusId` column can say where a ticket is; only a row
 * like this can say who moved it, when, and what it was before — which is exactly what somebody
 * asks three months later when a customer says nobody ever got back to them.
 */
class TicketEvent extends Model
{
    public ?int $id = null;
    public ?int $ticketId = null;
    public string $type = '';
    public ?int $userId = null;
    public ?DateTime $dateCreated = null;

    /** @var array<string, mixed> */
    private array $_data = [];

    private ?User $_user = null;
    private bool $_userLoaded = false;

    public function getType(): ?EventType
    {
        return EventType::tryFrom($this->type);
    }

    /** @return array<string, mixed> */
    public function getData(): array
    {
        return $this->_data;
    }

    public function setData(mixed $value): void
    {
        if (is_string($value)) {
            $value = Json::decodeIfJson($value);
        }

        $this->_data = is_array($value) ? $value : [];
    }

    public function getUser(): ?User
    {
        if (!$this->_userLoaded) {
            $this->_userLoaded = true;
            $this->_user = $this->userId !== null
                ? Craft::$app->getUsers()->getUserById($this->userId)
                : null;
        }

        return $this->_user;
    }

    public function getActorLabel(): string
    {
        return $this->getUser()?->getFriendlyName()
            ?? (string)($this->_data['actor'] ?? Craft::t('csr', 'The customer'));
    }

    /**
     * A sentence describing what happened, built from the data recorded at the time.
     *
     * From the *recorded* data rather than by looking things up now, because "moved from Open to
     * Pending" has to still say that after somebody renames the Pending status.
     */
    public function getDescription(): string
    {
        $data = $this->_data;

        return match ($this->getType()) {
            EventType::Created => Craft::t('csr', 'Ticket raised'),
            EventType::Replied => Craft::t('csr', 'Replied'),
            EventType::Noted => Craft::t('csr', 'Added an internal note'),
            EventType::StatusChanged => Craft::t('csr', 'Status changed from {from} to {to}', [
                'from' => $data['from'] ?? Craft::t('csr', 'nothing'),
                'to' => $data['to'] ?? '?',
            ]),
            EventType::PriorityChanged => Craft::t('csr', 'Priority changed from {from} to {to}', [
                'from' => $data['from'] ?? '?',
                'to' => $data['to'] ?? '?',
            ]),
            EventType::Assigned => Craft::t('csr', 'Assigned to {agent}', ['agent' => $data['agent'] ?? '?']),
            EventType::Unassigned => Craft::t('csr', 'Unassigned'),
            EventType::TeamChanged => Craft::t('csr', 'Moved to {team}', ['team' => $data['team'] ?? '?']),
            EventType::CategoryChanged => Craft::t('csr', 'Filed under {category}', ['category' => $data['category'] ?? '?']),
            EventType::SlaApplied => Craft::t('csr', '{policy} applied', ['policy' => $data['policy'] ?? '?']),
            EventType::SlaBreached => Craft::t('csr', '{target} target missed', [
                'target' => ($data['target'] ?? '') === 'resolution'
                    ? Craft::t('csr', 'Resolution')
                    : Craft::t('csr', 'First response'),
            ]),
            EventType::SlaEscalated => Craft::t('csr', 'Escalated to {team}', ['team' => $data['team'] ?? '?']),
            EventType::Merged => Craft::t('csr', 'Merged with ticket #{number}', ['number' => $data['number'] ?? '?']),
            EventType::Reopened => Craft::t('csr', 'Reopened'),
            EventType::Rated => Craft::t('csr', 'Rated {score} out of 5', ['score' => $data['score'] ?? '?']),
            EventType::Viewed => Craft::t('csr', 'Opened by the customer'),
            default => $this->type,
        };
    }
}
