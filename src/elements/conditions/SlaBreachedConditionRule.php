<?php

declare(strict_types=1);

namespace justinholtweb\csr\elements\conditions;

use Craft;
use craft\base\conditions\BaseLightswitchConditionRule;
use craft\base\ElementInterface;
use craft\elements\conditions\ElementConditionRuleInterface;
use craft\elements\db\ElementQueryInterface;
use craft\helpers\Db;
use DateTime;
use justinholtweb\csr\elements\Ticket;

/**
 * Late: past a first-response or resolution target, or already stamped as having been.
 *
 * The same two halves as the query's `breaching` param and {@see Ticket::getIsBreaching()} — the
 * stamped columns the sweep writes, and the live comparison that turns a ticket red twenty minutes
 * after its deadline on a site whose cron has stopped.
 */
class SlaBreachedConditionRule extends BaseLightswitchConditionRule implements ElementConditionRuleInterface
{
    public function getLabel(): string
    {
        return Craft::t('csr', 'Past an SLA target');
    }

    public function getExclusiveQueryParams(): array
    {
        return ['breaching'];
    }

    public static function breachCondition(): array
    {
        $now = Db::prepareDateForDb(new DateTime());

        return ['or',
            ['csr_tickets.firstResponseBreached' => true],
            ['csr_tickets.resolutionBreached' => true],
            ['and',
                ['csr_tickets.firstRespondedAt' => null],
                ['not', ['csr_tickets.firstResponseDue' => null]],
                ['<', 'csr_tickets.firstResponseDue', $now],
            ],
            ['and',
                ['csr_tickets.resolvedAt' => null],
                ['not', ['csr_tickets.resolutionDue' => null]],
                ['<', 'csr_tickets.resolutionDue', $now],
            ],
        ];
    }

    public function modifyQuery(ElementQueryInterface $query): void
    {
        $query->andWhere($this->value ? self::breachCondition() : ['not', self::breachCondition()]);
    }

    public function matchElement(ElementInterface $element): bool
    {
        /** @var Ticket $element */
        return $this->matchValue($element->getIsBreaching());
    }
}
