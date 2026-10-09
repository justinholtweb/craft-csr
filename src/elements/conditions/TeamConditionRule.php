<?php

declare(strict_types=1);

namespace justinholtweb\csr\elements\conditions;

use Craft;
use craft\base\conditions\BaseMultiSelectConditionRule;
use craft\base\ElementInterface;
use craft\elements\conditions\ElementConditionRuleInterface;
use craft\elements\db\ElementQueryInterface;
use justinholtweb\csr\elements\Ticket;
use justinholtweb\csr\Plugin;

/**
 * The team the ticket is routed to, or none. Stored by uid so a source survives a deploy. Teams
 * are Pro; on Lite there are none to choose, and the rule matches nothing rather than being
 * unregistered (see {@see TicketCondition}).
 */
class TeamConditionRule extends BaseMultiSelectConditionRule implements ElementConditionRuleInterface
{
    public const NONE = 'none';

    public function getLabel(): string
    {
        return Craft::t('csr', 'Team');
    }

    public function getExclusiveQueryParams(): array
    {
        return ['teamId'];
    }

    protected function options(): array
    {
        $options = [['value' => self::NONE, 'label' => Craft::t('csr', 'No team')]];

        foreach (Plugin::getInstance()->teams->getAllTeams() as $team) {
            $options[] = ['value' => (string)$team->uid, 'label' => $team->name];
        }

        return $options;
    }

    /** @return array{0: int[], 1: bool} */
    private function resolved(): array
    {
        $teams = Plugin::getInstance()->teams;
        $ids = [];
        $none = false;

        foreach ($this->getValues() as $value) {
            if ($value === self::NONE) {
                $none = true;
            } elseif (($team = $teams->getTeamByUid((string)$value)) !== null) {
                $ids[] = (int)$team->id;
            }
        }

        return [$ids, $none];
    }

    public function modifyQuery(ElementQueryInterface $query): void
    {
        [$ids, $none] = $this->resolved();
        $in = ['or'];

        if ($ids !== []) {
            $in[] = ['csr_tickets.teamId' => $ids];
        }

        if ($none) {
            $in[] = ['csr_tickets.teamId' => null];
        }

        if (count($in) === 1) {
            $in = '0=1';
        }

        $query->andWhere($this->operator === self::OPERATOR_NOT_IN ? ['not', $in] : $in);
    }

    public function matchElement(ElementInterface $element): bool
    {
        /** @var Ticket $element */
        [$ids, $none] = $this->resolved();
        $in = $element->teamId === null ? $none : in_array($element->teamId, $ids, true);

        return $this->operator === self::OPERATOR_NOT_IN ? !$in : $in;
    }
}
