<?php

declare(strict_types=1);

namespace justinholtweb\csr\services;

use Craft;
use craft\base\Component;
use craft\db\Query;
use craft\elements\User;
use craft\events\ConfigEvent;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use justinholtweb\csr\models\Edition;
use justinholtweb\csr\models\Team;
use justinholtweb\csr\Plugin;
use justinholtweb\csr\records\Table;
use justinholtweb\csr\records\TeamMemberRecord;
use justinholtweb\csr\records\TeamRecord;

/**
 * Groups of agents, and who is in them.
 *
 * The team is project config; the membership is not. User IDs differ between environments, and
 * somebody joining the support rota is not a deployment — pushing that through project config
 * would mean either a broken deploy or a team that is empty on production.
 */
class Teams extends Component
{
    public const CONFIG_KEY = 'csr.teams';

    /** @var Team[]|null */
    private ?array $_teams = null;

    /** @var array<int, int[]>|null Member user IDs, keyed by team ID. */
    private ?array $_membership = null;

    /** @return Team[] */
    public function getAllTeams(): array
    {
        if ($this->_teams !== null) {
            return $this->_teams;
        }

        $this->_teams = [];

        if (!Edition::allowsTeams(Plugin::getInstance()->isPro())) {
            // A lapsed licence does not delete anybody's teams; it stops them being consulted, so
            // routing quietly falls back to assigning a person, which Lite can do.
            return $this->_teams;
        }

        /** @var TeamRecord[] $records */
        $records = TeamRecord::find()
            ->orderBy(['sortOrder' => SORT_ASC, 'name' => SORT_ASC])
            ->all();

        foreach ($records as $record) {
            $this->_teams[] = new Team([
                'id' => (int)$record->id,
                'name' => $record->name,
                'handle' => $record->handle,
                'description' => $record->description,
                'color' => $record->color,
                'email' => $record->email,
                'sortOrder' => $record->sortOrder !== null ? (int)$record->sortOrder : null,
                'uid' => $record->uid,
            ]);
        }

        return $this->_teams;
    }

    public function getTeamById(?int $id): ?Team
    {
        if ($id === null) {
            return null;
        }

        foreach ($this->getAllTeams() as $team) {
            if ($team->id === $id) {
                return $team;
            }
        }

        return null;
    }

    public function getTeamByHandle(string $handle): ?Team
    {
        foreach ($this->getAllTeams() as $team) {
            if ($team->handle === $handle) {
                return $team;
            }
        }

        return null;
    }

    public function getTeamByUid(string $uid): ?Team
    {
        foreach ($this->getAllTeams() as $team) {
            if ($team->uid === $uid) {
                return $team;
            }
        }

        return null;
    }

    /** @return array<string, string> id => name, for a select. */
    public function getOptions(bool $includeBlank = true): array
    {
        $options = $includeBlank ? ['' => Craft::t('csr', 'No team')] : [];

        foreach ($this->getAllTeams() as $team) {
            $options[(string)$team->id] = $team->name;
        }

        return $options;
    }

    // Membership
    // -------------------------------------------------------------------------

    /** @return User[] */
    public function getMembers(int $teamId): array
    {
        $ids = $this->getMembership()[$teamId] ?? [];

        if ($ids === []) {
            return [];
        }

        return User::find()->id($ids)->status(null)->all();
    }

    /** @return int[] */
    public function getMemberIds(int $teamId): array
    {
        return $this->getMembership()[$teamId] ?? [];
    }

    /** @return Team[] The teams this user belongs to. */
    public function getTeamsForUser(int $userId): array
    {
        $teams = [];

        foreach ($this->getMembership() as $teamId => $userIds) {
            if (in_array($userId, $userIds, true)) {
                $team = $this->getTeamById($teamId);

                if ($team !== null) {
                    $teams[] = $team;
                }
            }
        }

        return $teams;
    }

    /** @param int[] $userIds */
    public function setMembers(int $teamId, array $userIds): void
    {
        $userIds = array_values(array_unique(array_map('intval', $userIds)));

        Db::delete(Table::TEAM_MEMBERS, ['teamId' => $teamId]);

        foreach ($userIds as $userId) {
            $record = new TeamMemberRecord();
            $record->teamId = $teamId;
            $record->userId = $userId;
            $record->save(false);
        }

        $this->_membership = null;
    }

    /** @return array<int, int[]> */
    private function getMembership(): array
    {
        if ($this->_membership !== null) {
            return $this->_membership;
        }

        $this->_membership = [];

        $rows = (new Query())
            ->select(['teamId', 'userId'])
            ->from([Table::TEAM_MEMBERS])
            ->all();

        foreach ($rows as $row) {
            $this->_membership[(int)$row['teamId']][] = (int)$row['userId'];
        }

        return $this->_membership;
    }

    // Writing
    // -------------------------------------------------------------------------

    public function saveTeam(Team $team, bool $runValidation = true): bool
    {
        if (!Edition::allowsTeams(Plugin::getInstance()->isPro())) {
            $team->addError('name', Craft::t('csr', 'Teams are a Pro feature.'));

            return false;
        }

        if ($runValidation && !$team->validate()) {
            return false;
        }

        $isNew = $team->id === null;

        if ($isNew) {
            $team->uid = StringHelper::UUID();
            $team->sortOrder ??= (int)((new Query())->from([Table::TEAMS])->max('[[sortOrder]]') ?? 0) + 1;
        } elseif (!$team->uid) {
            $team->uid = Db::uidById(Table::TEAMS, $team->id);
        }

        Craft::$app->getProjectConfig()->set(
            self::CONFIG_KEY . '.' . $team->uid,
            $team->getConfig(),
            "Save the “{$team->handle}” support team",
        );

        if ($isNew) {
            $team->id = Db::idByUid(Table::TEAMS, $team->uid);
        }

        // Null means the caller had no opinion about the rota — which is not the same as an empty
        // team, and must not empty one.
        $memberIds = $team->getMemberIds();

        if ($team->id !== null) {
            $this->setMembers((int)$team->id, $memberIds);
        }

        $this->refresh();

        return true;
    }

    public function deleteTeamById(int $id): bool
    {
        $team = $this->getTeamById($id);

        return $team !== null && $this->deleteTeam($team);
    }

    /**
     * Tickets routed to a deleted team fall back to being unrouted.
     *
     * `csr_tickets.teamId` is `SET NULL`, and an unrouted open ticket still appears in the
     * "unassigned" queue, which is where somebody will find it. Nothing is closed and nothing
     * disappears.
     */
    public function deleteTeam(Team $team): bool
    {
        Craft::$app->getProjectConfig()->remove(
            self::CONFIG_KEY . '.' . $team->uid,
            "Delete the “{$team->handle}” support team",
        );

        // Called directly as well as from the config handler, because project config coalesces
        // changes within one request: a path added and removed in the same run fires no `onRemove`
        // handler at all, and the membership rows would be orphaned. Both paths are idempotent.
        $this->removeTeamRecord((string)$team->uid);
        $this->refresh();

        return true;
    }

    public function handleChangedTeam(ConfigEvent $event): void
    {
        $uid = $event->tokenMatches[0];
        $data = $event->newValue;

        $record = TeamRecord::findOne(['uid' => $uid]) ?? new TeamRecord();
        $record->uid = $uid;
        $record->name = $data['name'];
        $record->handle = $data['handle'];
        $record->description = $data['description'] ?? null;
        $record->color = $data['color'] ?? 'blue';
        $record->email = $data['email'] ?? null;
        $record->sortOrder = $data['sortOrder'] ?? null;
        $record->save(false);

        $this->refresh();
    }

    public function handleDeletedTeam(ConfigEvent $event): void
    {
        $this->removeTeamRecord($event->tokenMatches[0]);
        $this->refresh();
    }

    private function removeTeamRecord(string $uid): void
    {
        $record = TeamRecord::findOne(['uid' => $uid]);

        if ($record !== null) {
            Db::delete(Table::TEAM_MEMBERS, ['teamId' => $record->id]);
            $record->delete();
        }
    }

    public function refresh(): void
    {
        $this->_teams = null;
        $this->_membership = null;
    }

    /** @return array<string, mixed> */
    public function rebuildProjectConfig(): array
    {
        $config = [];

        // Read the records directly rather than through `getAllTeams()`, which returns nothing on
        // Lite — a rebuild on a lapsed licence must not silently delete the teams from the YAML.
        /** @var TeamRecord[] $records */
        $records = TeamRecord::find()->all();
        foreach ($records as $record) {
            $config[$record->uid] = [
                'name' => $record->name,
                'handle' => $record->handle,
                'description' => $record->description ?: null,
                'color' => $record->color,
                'email' => $record->email ?: null,
                'sortOrder' => (int)$record->sortOrder,
            ];
        }

        return $config;
    }
}
