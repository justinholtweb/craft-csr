<?php

declare(strict_types=1);

namespace justinholtweb\csr\elements\conditions;

use craft\elements\conditions\ElementCondition;

/**
 * The condition behind custom ticket sources and the tickets index filter.
 *
 * Craft adds its generic element rules and one per field in the ticket field layout; these are the
 * desk's own — the things an agent actually sorts a queue by. "Late or due within two hours,
 * urgent or high, unassigned" is a custom source an agent builds in a minute and keeps open all
 * day.
 *
 * Every rule is registered whatever the edition: a rule registered only when a setting or licence
 * allows it is silently dropped from saved conditions when it doesn't, and the source then widens
 * to every ticket. The SLA rules simply match nothing on a desk with no SLAs.
 */
class TicketCondition extends ElementCondition
{
    protected function selectableConditionRules(): array
    {
        return array_merge(parent::selectableConditionRules(), [
            TicketStatusConditionRule::class,
            StatusKindConditionRule::class,
            PriorityConditionRule::class,
            AssigneeConditionRule::class,
            TeamConditionRule::class,
            CategoryConditionRule::class,
            SourceConditionRule::class,
            SlaBreachedConditionRule::class,
            SlaDueWithinConditionRule::class,
        ]);
    }
}
