<?php

declare(strict_types=1);

namespace justinholtweb\csr\elements\conditions;

use Craft;
use craft\base\conditions\BaseMultiSelectConditionRule;
use craft\base\ElementInterface;
use craft\elements\conditions\ElementConditionRuleInterface;
use craft\elements\db\ElementQueryInterface;
use justinholtweb\csr\elements\db\TicketQuery;
use justinholtweb\csr\elements\Ticket;
use justinholtweb\csr\enums\Priority;

/** Priority is one of / is not one of. */
class PriorityConditionRule extends BaseMultiSelectConditionRule implements ElementConditionRuleInterface
{
    public function getLabel(): string
    {
        return Craft::t('csr', 'Priority');
    }

    public function getExclusiveQueryParams(): array
    {
        return ['priority'];
    }

    protected function options(): array
    {
        return array_map(
            static fn(Priority $priority) => ['value' => $priority->value, 'label' => $priority->label()],
            Priority::cases(),
        );
    }

    public function modifyQuery(ElementQueryInterface $query): void
    {
        /** @var TicketQuery $query */
        $value = $this->paramValue();

        // Nothing chosen that still exists: "is one of" matches nothing. A null param would
        // mean "do not filter", and the source would quietly show every ticket.
        if ($value === null) {
            $query->andWhere($this->operator === self::OPERATOR_NOT_IN ? '1=1' : '0=1');

            return;
        }

        $query->priority($value);
    }

    public function matchElement(ElementInterface $element): bool
    {
        /** @var Ticket $element */
        return $this->matchValue($element->priority);
    }
}
