<?php

declare(strict_types=1);

namespace justinholtweb\csr\services;

use Craft;
use craft\base\Component;
use craft\db\Query;
use craft\events\ConfigEvent;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use justinholtweb\csr\enums\StatusKind;
use justinholtweb\csr\models\Edition;
use justinholtweb\csr\models\Status;
use justinholtweb\csr\Plugin;
use justinholtweb\csr\records\StatusRecord;
use justinholtweb\csr\records\Table;

/**
 * The states a ticket can be in, backed by project config.
 *
 * Project config because a support process is configuration — reviewable, deployable, and the same
 * on staging as on production — and mirrored into `csr_statuses` so a ticket can point at one with
 * an integer foreign key and the control panel can list them with a query rather than by walking
 * YAML on every request.
 */
class Statuses extends Component
{
    public const CONFIG_KEY = 'csr.statuses';

    /** @var Status[]|null */
    private ?array $_statuses = null;

    /** @return Status[] In process order. */
    public function getAllStatuses(): array
    {
        if ($this->_statuses !== null) {
            return $this->_statuses;
        }

        $this->_statuses = [];

        /** @var StatusRecord[] $records */
        $records = StatusRecord::find()
            ->orderBy(['sortOrder' => SORT_ASC, 'name' => SORT_ASC])
            ->all();

        foreach ($records as $record) {
            $this->_statuses[] = new Status([
                'id' => (int)$record->id,
                'name' => $record->name,
                'handle' => $record->handle,
                'kind' => $record->kind,
                'color' => $record->color,
                'description' => $record->description,
                'isDefault' => (bool)$record->isDefault,
                'notifyRequester' => (bool)$record->notifyRequester,
                'emailSubject' => $record->emailSubject,
                'emailBody' => $record->emailBody,
                'sortOrder' => $record->sortOrder !== null ? (int)$record->sortOrder : null,
                'uid' => $record->uid,
            ]);
        }

        return $this->_statuses;
    }

    public function getStatusById(?int $id): ?Status
    {
        if ($id === null) {
            return null;
        }

        foreach ($this->getAllStatuses() as $status) {
            if ($status->id === $id) {
                return $status;
            }
        }

        return null;
    }

    public function getStatusByHandle(string $handle): ?Status
    {
        foreach ($this->getAllStatuses() as $status) {
            if ($status->handle === $handle) {
                return $status;
            }
        }

        return null;
    }

    public function getStatusByUid(string $uid): ?Status
    {
        foreach ($this->getAllStatuses() as $status) {
            if ($status->uid === $uid) {
                return $status;
            }
        }

        return null;
    }

    /** Where new tickets land. */
    public function getDefaultStatus(): ?Status
    {
        $statuses = $this->getAllStatuses();

        foreach ($statuses as $status) {
            if ($status->isDefault) {
                return $status;
            }
        }

        return $statuses[0] ?? null;
    }

    /** @return Status[] */
    public function getStatusesByKind(StatusKind $kind): array
    {
        return array_values(array_filter(
            $this->getAllStatuses(),
            static fn(Status $status) => $status->getKind() === $kind,
        ));
    }

    /**
     * The first status of a given kind, for the automatic transitions.
     *
     * Used by "an agent replied, so this is no longer new", by the auto-close sweep, and by the
     * reopen-on-reply rule. Each of those has to move a ticket somewhere without knowing what this
     * desk calls it.
     */
    public function getFirstOfKind(StatusKind $kind): ?Status
    {
        return $this->getStatusesByKind($kind)[0] ?? null;
    }

    /** @return array<string, string> id => name, for a select. */
    public function getOptions(): array
    {
        $options = [];

        foreach ($this->getAllStatuses() as $status) {
            $options[(string)$status->id] = $status->name;
        }

        return $options;
    }

    public function canCreateStatus(): bool
    {
        return !Edition::statusLimitReached(count($this->getAllStatuses()), Plugin::getInstance()->isPro());
    }

    public function saveStatus(Status $status, bool $runValidation = true): bool
    {
        $isNew = $status->id === null;

        if ($isNew && !$this->canCreateStatus()) {
            $status->addError('name', Craft::t('csr', 'CSR Lite supports {max} ticket statuses. Upgrade to Pro to model your own process.', [
                'max' => Edition::LITE_MAX_STATUSES,
            ]));

            return false;
        }

        if ($runValidation && !$status->validate()) {
            return false;
        }

        if ($isNew) {
            $status->uid = StringHelper::UUID();
            $status->sortOrder ??= (int)((new Query())->from([Table::STATUSES])->max('[[sortOrder]]') ?? 0) + 1;
        } elseif (!$status->uid) {
            $status->uid = Db::uidById(Table::STATUSES, $status->id);
        }

        Craft::$app->getProjectConfig()->set(
            self::CONFIG_KEY . '.' . $status->uid,
            $status->getConfig(),
            "Save the “{$status->handle}” ticket status",
        );

        if ($isNew) {
            $status->id = Db::idByUid(Table::STATUSES, $status->uid);
        }

        if ($status->isDefault) {
            $this->clearOtherDefaults($status);
        }

        $this->refresh();

        return true;
    }

    private function clearOtherDefaults(Status $default): void
    {
        $projectConfig = Craft::$app->getProjectConfig();

        foreach ($this->getAllStatuses() as $status) {
            if ($status->uid === $default->uid || !$status->isDefault) {
                continue;
            }

            $status->isDefault = false;
            $projectConfig->set(self::CONFIG_KEY . '.' . $status->uid, $status->getConfig(), 'Clear a previous default status');
        }
    }

    /** @param string[] $uids In the order they should appear. */
    public function reorderStatuses(array $uids): bool
    {
        $projectConfig = Craft::$app->getProjectConfig();

        foreach ($uids as $index => $uid) {
            $status = $this->getStatusByUid((string)$uid);

            if ($status === null) {
                continue;
            }

            $status->sortOrder = $index + 1;
            $projectConfig->set(self::CONFIG_KEY . '.' . $uid . '.sortOrder', $index + 1, 'Reorder ticket statuses');
        }

        $this->refresh();

        return true;
    }

    public function deleteStatusById(int $id): bool
    {
        $status = $this->getStatusById($id);

        return $status !== null && $this->deleteStatus($status);
    }

    /**
     * Tickets on a deleted status keep their history and lose their place.
     *
     * `csr_tickets.statusId` is `SET NULL`, and a ticket with no status reads as open — which is
     * the safe way round, because the alternative is a customer's unanswered question quietly
     * becoming invisible. Reassigning them would rewrite decisions somebody made, and the event
     * log would then disagree with the column.
     */
    public function deleteStatus(Status $status): bool
    {
        if (count($this->getAllStatuses()) === 1) {
            $status->addError('name', Craft::t('csr', 'The last status can’t be deleted — tickets need somewhere to land.'));

            return false;
        }

        Craft::$app->getProjectConfig()->remove(
            self::CONFIG_KEY . '.' . $status->uid,
            "Delete the “{$status->handle}” ticket status",
        );

        $this->removeStatusRecord((string)$status->uid);
        $this->refresh();

        return true;
    }

    public function handleChangedStatus(ConfigEvent $event): void
    {
        $uid = $event->tokenMatches[0];
        $data = $event->newValue;

        $record = StatusRecord::findOne(['uid' => $uid]) ?? new StatusRecord();
        $record->uid = $uid;
        $record->name = $data['name'];
        $record->handle = $data['handle'];
        $record->kind = $data['kind'] ?? StatusKind::Open->value;
        $record->color = $data['color'] ?? 'gray';
        $record->description = $data['description'] ?? null;
        $record->isDefault = (bool)($data['isDefault'] ?? false);
        $record->notifyRequester = (bool)($data['notifyRequester'] ?? false);
        $record->emailSubject = $data['emailSubject'] ?? null;
        $record->emailBody = $data['emailBody'] ?? null;
        $record->sortOrder = $data['sortOrder'] ?? null;
        $record->save(false);

        $this->refresh();
    }

    public function handleDeletedStatus(ConfigEvent $event): void
    {
        $this->removeStatusRecord($event->tokenMatches[0]);
        $this->refresh();
    }

    private function removeStatusRecord(string $uid): void
    {
        StatusRecord::findOne(['uid' => $uid])?->delete();
    }

    public function refresh(): void
    {
        $this->_statuses = null;
    }

    /** @return array<string, mixed> */
    public function rebuildProjectConfig(): array
    {
        $config = [];

        foreach ($this->getAllStatuses() as $status) {
            if ($status->uid !== null) {
                $config[$status->uid] = $status->getConfig();
            }
        }

        return $config;
    }

    /**
     * The five statuses a new install starts with.
     *
     * One of each kind, which is the smallest set that makes every automatic transition in the
     * plugin work: a new ticket has somewhere to land, an agent replying has somewhere to move it,
     * waiting on the customer stops the clock, and resolving and closing are distinguishable —
     * which matters, because "resolved" is a claim the desk makes and "closed" is what happens
     * when nobody argues with it.
     */
    public function installDefaults(): void
    {
        if ($this->getAllStatuses() !== []) {
            return;
        }

        $defaults = [
            ['name' => 'New', 'handle' => 'new', 'kind' => StatusKind::New, 'color' => 'blue', 'isDefault' => true],
            ['name' => 'Open', 'handle' => 'open', 'kind' => StatusKind::Open, 'color' => 'teal'],
            ['name' => 'Waiting on customer', 'handle' => 'pending', 'kind' => StatusKind::Pending, 'color' => 'orange'],
            ['name' => 'Resolved', 'handle' => 'resolved', 'kind' => StatusKind::Resolved, 'color' => 'green'],
            ['name' => 'Closed', 'handle' => 'closed', 'kind' => StatusKind::Closed, 'color' => 'gray'],
        ];

        foreach ($defaults as $index => $default) {
            $this->saveStatus(new Status([
                'name' => $default['name'],
                'handle' => $default['handle'],
                'kind' => $default['kind']->value,
                'color' => $default['color'],
                'isDefault' => $default['isDefault'] ?? false,
                'sortOrder' => $index + 1,
            ]), false);
        }
    }
}
