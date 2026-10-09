<?php

declare(strict_types=1);

namespace justinholtweb\csr\elements\conditions;

use Craft;
use craft\base\conditions\BaseConditionRule;
use craft\base\ElementInterface;
use craft\elements\conditions\ElementConditionRuleInterface;
use craft\elements\db\ElementQueryInterface;
use craft\helpers\Cp;
use craft\helpers\Db;
use craft\helpers\Html;
use DateTime;
use justinholtweb\csr\elements\Ticket;

/**
 * "Due within N hours": an unmet target — first response not yet given, or resolution not yet
 * reached — falling between now and N hours from now.
 *
 * Not yet late: a ticket already past its target is the other rule's. Together they make the two
 * sources a desk lives in, "late" and "about to be".
 *
 * No operator, so Craft's full `validate()` would trip on the unset operator; validate `hours`
 * only (see the family notes on operator-less rules).
 */
class SlaDueWithinConditionRule extends BaseConditionRule implements ElementConditionRuleInterface
{
    /**
     * Private behind a setter: Yii configures a rule by assigning to a public property directly,
     * and the number input posts `''` when it is cleared — a TypeError on a typed `int`.
     */
    private int $_hours = 2;

    public function getLabel(): string
    {
        return Craft::t('csr', 'SLA due within (hours)');
    }

    public function getExclusiveQueryParams(): array
    {
        return [];
    }

    public function getConfig(): array
    {
        return array_merge(parent::getConfig(), ['hours' => $this->_hours]);
    }

    public function getHours(): int
    {
        return $this->_hours;
    }

    /** Posted as a string from the number input; anything unreadable or negative is the default. */
    public function setHours(mixed $value): void
    {
        $this->_hours = is_numeric($value) && (int)$value >= 0 ? min(24 * 365, (int)$value) : 2;
    }

    protected function inputHtml(): string
    {
        return Html::hiddenLabel(Html::encode($this->getLabel()), 'hours') .
            Cp::textHtml([
                'type' => 'number',
                'id' => 'hours',
                'name' => 'hours',
                'value' => $this->_hours,
                'min' => 0,
                'size' => 5,
            ]);
    }

    protected function defineRules(): array
    {
        return array_merge(parent::defineRules(), [
            [['hours'], 'integer', 'min' => 0],
        ]);
    }

    public function modifyQuery(ElementQueryInterface $query): void
    {
        $now = Db::prepareDateForDb(new DateTime());
        $until = Db::prepareDateForDb(new DateTime('+' . $this->_hours . ' hours'));

        $query->andWhere(['or',
            ['and',
                ['csr_tickets.firstRespondedAt' => null],
                ['>=', 'csr_tickets.firstResponseDue', $now],
                ['<=', 'csr_tickets.firstResponseDue', $until],
            ],
            ['and',
                ['csr_tickets.resolvedAt' => null],
                ['>=', 'csr_tickets.resolutionDue', $now],
                ['<=', 'csr_tickets.resolutionDue', $until],
            ],
        ]);
    }

    public function matchElement(ElementInterface $element): bool
    {
        /** @var Ticket $element */
        $limit = $this->_hours * 3600;

        foreach ([$element->getFirstResponseRemaining(), $element->getResolutionRemaining()] as $remaining) {
            if ($remaining !== null && $remaining >= 0 && $remaining <= $limit) {
                return true;
            }
        }

        return false;
    }
}
