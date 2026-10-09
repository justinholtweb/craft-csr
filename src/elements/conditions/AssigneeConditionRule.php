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
 * Who the ticket is assigned to, with "Unassigned" and "Me" as choices of their own.
 *
 * "Me" is resolved when the query runs, so one custom source — "Mine, urgent" — works for every
 * agent who opens it rather than being one person's.
 */
class AssigneeConditionRule extends BaseMultiSelectConditionRule implements ElementConditionRuleInterface
{
    public const UNASSIGNED = 'unassigned';
    public const ME = 'me';

    public function getLabel(): string
    {
        return Craft::t('csr', 'Assigned to');
    }

    public function getExclusiveQueryParams(): array
    {
        return ['agentId'];
    }

    protected function options(): array
    {
        $options = [
            ['value' => self::ME, 'label' => Craft::t('csr', 'Me')],
            ['value' => self::UNASSIGNED, 'label' => Craft::t('csr', 'Unassigned')],
        ];

        foreach (Plugin::getInstance()->tickets->getAssignableAgents() as $id) {
            $user = Craft::$app->getUsers()->getUserById($id);

            if ($user !== null) {
                $options[] = ['value' => (string)$id, 'label' => $user->getFriendlyName() ?: (string)$user->email];
            }
        }

        return $options;
    }

    /** @return array{0: int[], 1: bool} The agent IDs chosen, and whether "unassigned" was. */
    private function resolved(): array
    {
        $ids = [];
        $unassigned = false;

        foreach ($this->getValues() as $value) {
            if ($value === self::UNASSIGNED) {
                $unassigned = true;
            } elseif ($value === self::ME) {
                // Nobody signed in (a console run, a queue job): "me" is nobody, not everybody.
                $ids[] = (int)(Craft::$app->getUser()->getId() ?? 0);
            } elseif (is_numeric($value)) {
                $ids[] = (int)$value;
            }
        }

        return [$ids, $unassigned];
    }

    public function modifyQuery(ElementQueryInterface $query): void
    {
        [$ids, $unassigned] = $this->resolved();
        $in = ['or'];

        if ($ids !== []) {
            $in[] = ['csr_tickets.agentId' => $ids];
        }

        if ($unassigned) {
            $in[] = ['csr_tickets.agentId' => null];
        }

        if (count($in) === 1) {
            $in = '0=1';
        }

        $query->andWhere($this->operator === self::OPERATOR_NOT_IN ? ['not', $in] : $in);
    }

    public function matchElement(ElementInterface $element): bool
    {
        /** @var Ticket $element */
        [$ids, $unassigned] = $this->resolved();
        $in = $element->agentId === null ? $unassigned : in_array($element->agentId, $ids, true);

        return $this->operator === self::OPERATOR_NOT_IN ? !$in : $in;
    }
}
