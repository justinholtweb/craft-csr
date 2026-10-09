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
use justinholtweb\csr\Plugin;

/**
 * The category the ticket was raised about. A parent category includes its children, the way the
 * knowledge base files them.
 */
class CategoryConditionRule extends BaseMultiSelectConditionRule implements ElementConditionRuleInterface
{
    public function getLabel(): string
    {
        return Craft::t('csr', 'Category');
    }

    public function getExclusiveQueryParams(): array
    {
        return ['categoryId'];
    }

    protected function options(): array
    {
        $options = [];

        foreach (Plugin::getInstance()->categories->getOptions(false, false) as $id => $label) {
            $options[] = ['value' => (string)$id, 'label' => trim((string)$label)];
        }

        return $options;
    }

    /** @return int[] */
    private function expandedIds(): array
    {
        $categories = Plugin::getInstance()->categories;
        $ids = [];

        foreach ($this->getValues() as $id) {
            foreach ($categories->getIdsWithDescendants((int)$id) as $descendant) {
                $ids[(int)$descendant] = true;
            }
        }

        return array_keys($ids);
    }

    public function modifyQuery(ElementQueryInterface $query): void
    {
        $ids = $this->expandedIds();

        /** @var TicketQuery $query */
        if ($this->operator === self::OPERATOR_NOT_IN) {
            $query->andWhere($ids !== [] ? ['or', ['not', ['csr_tickets.categoryId' => $ids]], ['csr_tickets.categoryId' => null]] : '1=1');

            return;
        }

        $query->andWhere($ids !== [] ? ['csr_tickets.categoryId' => $ids] : '0=1');
    }

    public function matchElement(ElementInterface $element): bool
    {
        /** @var Ticket $element */
        $in = $element->categoryId !== null && in_array($element->categoryId, $this->expandedIds(), true);

        return $this->operator === self::OPERATOR_NOT_IN ? !$in : $in;
    }
}
