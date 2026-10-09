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
 * The desk's own status. Stored by uid, so a saved source survives a deploy to an environment
 * where the status has a different ID.
 */
class TicketStatusConditionRule extends BaseMultiSelectConditionRule implements ElementConditionRuleInterface
{
    public function getLabel(): string
    {
        return Craft::t('csr', 'Ticket status');
    }

    public function getExclusiveQueryParams(): array
    {
        return ['statusId'];
    }

    protected function options(): array
    {
        $options = [];

        foreach (Plugin::getInstance()->statuses->getAllStatuses() as $status) {
            $options[] = ['value' => (string)$status->uid, 'label' => $status->name];
        }

        return $options;
    }

    public function modifyQuery(ElementQueryInterface $query): void
    {
        $statuses = Plugin::getInstance()->statuses;

        /** @var TicketQuery $query */
        $value = $this->paramValue(static fn(string $uid) => $statuses->getStatusByUid($uid)?->id);

        // Nothing chosen that still exists: "is one of" matches nothing. A null param would
        // mean "do not filter", and the source would quietly show every ticket.
        if ($value === null) {
            $query->andWhere($this->operator === self::OPERATOR_NOT_IN ? '1=1' : '0=1');

            return;
        }

        $query->statusId($value);
    }

    public function matchElement(ElementInterface $element): bool
    {
        /** @var Ticket $element */
        return $this->matchValue($element->getTicketStatus()?->uid);
    }
}
