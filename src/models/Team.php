<?php

declare(strict_types=1);

namespace justinholtweb\csr\models;

use craft\base\Model;
use craft\elements\User;
use craft\helpers\UrlHelper;
use craft\validators\HandleValidator;
use justinholtweb\csr\Plugin;

/**
 * A group of agents a ticket can be routed to instead of to a person.
 *
 * Routing to a *person* is how tickets go missing: somebody is on holiday, somebody left, and the
 * ticket sits assigned to an account that nobody logs into. A team always has somebody in it.
 *
 * The team is project config; its **membership is not**. User IDs differ between environments and
 * somebody joining the support rota is not a deployment.
 *
 * @property-read User[] $members
 */
class Team extends Model
{
    public ?int $id = null;
    public string $name = '';
    public string $handle = '';
    public ?string $description = null;
    public string $color = 'blue';

    /** A shared address copied on the team's tickets, separate from the members' own. */
    public ?string $email = null;

    public ?int $sortOrder = null;
    public ?string $uid = null;

    /** @var User[]|null */
    private ?array $_members = null;

    /** @var int[]|null Set by the editor; written on save. Null means "nobody touched them". */
    private ?array $_memberIds = null;

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
            [['email'], 'email'],
            [['color'], 'string', 'max' => 16],
            [['sortOrder'], 'integer'],
        ];
    }

    /** @return User[] */
    public function getMembers(): array
    {
        return $this->_members ??= $this->id !== null
            ? Plugin::getInstance()->teams->getMembers($this->id)
            : [];
    }

    /** @return int[] */
    public function getMemberIds(): array
    {
        return $this->_memberIds ?? array_map(static fn(User $user) => (int)$user->id, $this->getMembers());
    }

    /**
     * @param int[]|null $ids Null means the caller had no opinion, which is not the same as an
     *                        empty team — a save that does not mention the members must not empty
     *                        the rota.
     */
    public function setMemberIds(?array $ids): void
    {
        $this->_memberIds = $ids === null ? null : array_values(array_unique(array_map('intval', $ids)));
        $this->_members = null;
    }

    public function getCpEditUrl(): string
    {
        return UrlHelper::cpUrl('csr/settings/teams/' . ($this->id ?? 'new'));
    }

    /** @return array<string, mixed> */
    public function getConfig(): array
    {
        return [
            'name' => $this->name,
            'handle' => $this->handle,
            'description' => $this->description ?: null,
            'color' => $this->color,
            'email' => $this->email ?: null,
            'sortOrder' => (int)$this->sortOrder,
        ];
    }
}
