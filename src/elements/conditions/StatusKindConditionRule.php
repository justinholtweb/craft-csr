<?php

declare(strict_types=1);

namespace justinholtweb\csr\elements\conditions;

use Craft;
use craft\base\conditions\BaseMultiSelectConditionRule;
use craft\base\ElementInterface;
use craft\elements\conditions\ElementConditionRuleInterface;
use craft\elements\db\ElementQueryInterface;
use justinholtweb\csr\elements\Ticket;
use justinholtweb\csr\enums\StatusKind;
use justinholtweb\csr\Plugin;

/**
 * What *kind* of status the ticket is in — new, open, pending, resolved, closed — whatever this
 * desk calls its statuses. A source built on "pending" keeps working when somebody renames
 * "Waiting on customer" to "On hold".
 */
class StatusKindConditionRule extends BaseMultiSelectConditionRule implements ElementConditionRuleInterface
{
    public function getLabel(): string
    {
        return Craft::t('csr', 'Status kind');
    }

    public function getExclusiveQueryParams(): array
    {
        return ['statusKind'];
    }

    protected function options(): array
    {
        return array_map(
            static fn(StatusKind $kind) => ['value' => $kind->value, 'label' => $kind->label()],
            StatusKind::cases(),
        );
    }

    public function modifyQuery(ElementQueryInterface $query): void
    {
        $kinds = array_values(array_filter(array_map(
            static fn($value) => StatusKind::tryFrom((string)$value),
            $this->getValues(),
        )));

        $ids = [];

        foreach (Plugin::getInstance()->statuses->getAllStatuses() as $status) {
            if (in_array($status->getKind(), $kinds, true)) {
                $ids[] = (int)$status->id;
            }
        }

        // By status ID, so "is not one of" works as well as "is one of". A kind no status has
        // matches nothing — or, negated, everything.
        $condition = $ids !== [] ? ['csr_tickets.statusId' => $ids] : '0=1';

        $query->andWhere($this->operator === self::OPERATOR_NOT_IN
            ? ['or', ['not', $condition], ['csr_tickets.statusId' => null]]
            : $condition);
    }

    public function matchElement(ElementInterface $element): bool
    {
        /** @var Ticket $element */
        return $this->matchValue($element->getTicketStatus()?->getKind()->value);
    }
}
