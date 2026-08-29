<?php

declare(strict_types=1);

namespace justinholtweb\csr\elements\db;

use craft\elements\db\ElementQuery;
use craft\helpers\Db;
use justinholtweb\csr\enums\StatusKind;
use justinholtweb\csr\Plugin;
use justinholtweb\csr\records\Table;
use yii\db\Expression;

/**
 * @method \justinholtweb\csr\elements\Ticket[] all($db = null)
 * @method \justinholtweb\csr\elements\Ticket|null one($db = null)
 * @method \justinholtweb\csr\elements\Ticket|null nth(int $n, $db = null)
 *
 * As with {@see ArticleQuery}, every parameter an element-index source may set is typed `mixed`:
 * Craft configures a source's query by assigning straight to public properties, and a narrower
 * type takes the whole index down for whichever source nobody clicked while testing.
 */
class TicketQuery extends ElementQuery
{
    public mixed $number = null;
    public mixed $reference = null;
    public mixed $statusId = null;

    /** @var mixed A {@see StatusKind} value — "everything that is pending", whatever it is called. */
    public mixed $statusKind = null;

    public mixed $priority = null;
    public mixed $categoryId = null;

    /** @var mixed Agent user IDs, or `false` for unassigned. */
    public mixed $agentId = null;

    public mixed $teamId = null;
    public mixed $requesterId = null;
    public mixed $requesterEmail = null;
    public mixed $submittedSiteId = null;
    public mixed $isSpam = false;
    public mixed $source = null;

    /** @var mixed Only tickets that are still somebody's problem. */
    public mixed $openOnly = null;

    /** @var mixed Nobody at the desk has replied yet. */
    public mixed $awaitingFirstResponse = null;

    /** @var mixed The customer replied last, so the ball is with the desk. */
    public mixed $awaitingUs = null;

    /** @var mixed Past one of its due times, or already stamped as having been. */
    public mixed $breaching = null;

    public mixed $submittedAfter = null;
    public mixed $submittedBefore = null;
    public mixed $satisfaction = null;

    protected array $defaultOrderBy = ['csr_tickets.submittedAt' => SORT_DESC];

    public function number(mixed $value): static
    {
        $this->number = $value;

        return $this;
    }

    public function reference(mixed $value): static
    {
        $this->reference = $value;

        return $this;
    }

    public function statusId(mixed $value): static
    {
        $this->statusId = $value;

        return $this;
    }

    /** @param mixed $value A {@see StatusKind} case or its value. */
    public function statusKind(mixed $value): static
    {
        $this->statusKind = $value;

        return $this;
    }

    public function priority(mixed $value): static
    {
        $this->priority = $value;

        return $this;
    }

    public function categoryId(mixed $value): static
    {
        $this->categoryId = $value;

        return $this;
    }

    public function agentId(mixed $value): static
    {
        $this->agentId = $value;

        return $this;
    }

    public function teamId(mixed $value): static
    {
        $this->teamId = $value;

        return $this;
    }

    public function requesterId(mixed $value): static
    {
        $this->requesterId = $value;

        return $this;
    }

    public function requesterEmail(mixed $value): static
    {
        $this->requesterEmail = $value;

        return $this;
    }

    public function submittedSiteId(mixed $value): static
    {
        $this->submittedSiteId = $value;

        return $this;
    }

    public function source(mixed $value): static
    {
        $this->source = $value;

        return $this;
    }

    public function isSpam(mixed $value = true): static
    {
        $this->isSpam = $value;

        return $this;
    }

    public function openOnly(mixed $value = true): static
    {
        $this->openOnly = $value;

        return $this;
    }

    public function awaitingFirstResponse(mixed $value = true): static
    {
        $this->awaitingFirstResponse = $value;

        return $this;
    }

    public function awaitingUs(mixed $value = true): static
    {
        $this->awaitingUs = $value;

        return $this;
    }

    public function breaching(mixed $value = true): static
    {
        $this->breaching = $value;

        return $this;
    }

    public function submittedAfter(mixed $value): static
    {
        $this->submittedAfter = $value;

        return $this;
    }

    public function submittedBefore(mixed $value): static
    {
        $this->submittedBefore = $value;

        return $this;
    }

    public function satisfaction(mixed $value): static
    {
        $this->satisfaction = $value;

        return $this;
    }

    protected function beforePrepare(): bool
    {
        if ($this->elementType === null) {
            return parent::beforePrepare();
        }

        $this->joinElementTable('csr_tickets');

        $this->query->addSelect([
            'csr_tickets.number',
            'csr_tickets.reference',
            'csr_tickets.statusId',
            'csr_tickets.priority',
            'csr_tickets.categoryId',
            'csr_tickets.agentId',
            'csr_tickets.teamId',
            'csr_tickets.requesterId',
            'csr_tickets.requesterEmail',
            'csr_tickets.requesterName',
            'csr_tickets.submittedSiteId',
            'csr_tickets.source',
            'csr_tickets.message',
            'csr_tickets.answers',
            'csr_tickets.slaPolicyId',
            'csr_tickets.firstResponseDue',
            'csr_tickets.resolutionDue',
            'csr_tickets.firstRespondedAt',
            'csr_tickets.resolvedAt',
            'csr_tickets.closedAt',
            'csr_tickets.lastReplyAt',
            'csr_tickets.lastReplyBy',
            'csr_tickets.firstResponseBreached',
            'csr_tickets.resolutionBreached',
            'csr_tickets.escalatedAt',
            'csr_tickets.clockPausedAt',
            'csr_tickets.pausedSeconds',
            'csr_tickets.replyCount',
            'csr_tickets.agentReplyCount',
            'csr_tickets.deflectionId',
            'csr_tickets.pigeonThreadId',
            'csr_tickets.satisfaction',
            'csr_tickets.satisfactionComment',
            'csr_tickets.isSpam',
            'csr_tickets.mergedIntoId',
            'csr_tickets.ipAddress',
            'csr_tickets.userAgent',
            'csr_tickets.submittedAt',
        ]);

        foreach ([
            'number' => 'csr_tickets.number',
            'statusId' => 'csr_tickets.statusId',
            'categoryId' => 'csr_tickets.categoryId',
            'teamId' => 'csr_tickets.teamId',
            'requesterId' => 'csr_tickets.requesterId',
            'submittedSiteId' => 'csr_tickets.submittedSiteId',
        ] as $param => $column) {
            if ($this->$param !== null) {
                $this->subQuery->andWhere(Db::parseNumericParam($column, $this->$param));
            }
        }

        foreach ([
            'reference' => 'csr_tickets.reference',
            'priority' => 'csr_tickets.priority',
            'requesterEmail' => 'csr_tickets.requesterEmail',
            'source' => 'csr_tickets.source',
        ] as $param => $column) {
            if ($this->$param !== null) {
                $this->subQuery->andWhere(Db::parseParam($column, $this->$param));
            }
        }

        // Unassigned is `false`, because null already means "do not filter" everywhere in Craft.
        if ($this->agentId === false) {
            $this->subQuery->andWhere(['csr_tickets.agentId' => null]);
        } elseif ($this->agentId !== null) {
            $this->subQuery->andWhere(Db::parseNumericParam('csr_tickets.agentId', $this->agentId));
        }

        if ($this->isSpam !== null) {
            $this->subQuery->andWhere(Db::parseBooleanParam('csr_tickets.isSpam', $this->isSpam, false));
        }

        if ($this->satisfaction !== null) {
            $this->subQuery->andWhere(Db::parseNumericParam('csr_tickets.satisfaction', $this->satisfaction));
        }

        if ($this->submittedAfter !== null) {
            $this->subQuery->andWhere(Db::parseDateParam('csr_tickets.submittedAt', $this->submittedAfter, '>='));
        }

        if ($this->submittedBefore !== null) {
            $this->subQuery->andWhere(Db::parseDateParam('csr_tickets.submittedAt', $this->submittedBefore, '<'));
        }

        $this->applyStatusKind();

        if ($this->openOnly !== null && $this->openOnly !== false) {
            $this->subQuery->andWhere($this->statusIdCondition(
                static fn(StatusKind $kind) => $kind->isOpen(),
            ));
        }

        if ($this->awaitingFirstResponse !== null && $this->awaitingFirstResponse !== false) {
            $this->subQuery->andWhere(['csr_tickets.firstRespondedAt' => null]);
        }

        if ($this->awaitingUs !== null && $this->awaitingUs !== false) {
            $this->subQuery->andWhere(['or',
                ['csr_tickets.lastReplyBy' => null],
                ['not', ['csr_tickets.lastReplyBy' => 'agent']],
            ]);
        }

        if ($this->breaching !== null && $this->breaching !== false) {
            $this->applyBreaching();
        }

        return parent::beforePrepare();
    }

    /**
     * "Everything pending", whatever this desk calls pending.
     *
     * Resolved by asking the statuses service which IDs have that kind, rather than by joining the
     * statuses table: the list is a handful of rows that are cached for the request anyway, and an
     * `IN (…)` is both faster and immune to the join multiplying rows against Craft's own
     * sub-query.
     */
    private function applyStatusKind(): void
    {
        if ($this->statusKind === null) {
            return;
        }

        $wanted = array_map(
            static fn($kind) => $kind instanceof StatusKind ? $kind : StatusKind::from((string)$kind),
            is_array($this->statusKind) ? $this->statusKind : [$this->statusKind],
        );

        $this->subQuery->andWhere($this->statusIdCondition(
            static fn(StatusKind $kind) => in_array($kind, $wanted, true),
        ));
    }

    /**
     * A condition matching the statuses whose kind passes the test.
     *
     * @param callable(StatusKind): bool $test
     */
    private function statusIdCondition(callable $test): array|string
    {
        $ids = [];

        foreach (Plugin::getInstance()->statuses->getAllStatuses() as $status) {
            if ($test($status->getKind())) {
                $ids[] = (int)$status->id;
            }
        }

        // A desk with no matching status has no matching tickets. Returning nothing here would
        // widen the query to everything, which is the opposite of what was asked.
        return $ids !== [] ? ['csr_tickets.statusId' => $ids] : '0=1';
    }

    /**
     * Late, or already recorded as having been late.
     *
     * Both halves are needed. The stamped columns are what the sweep writes and what the reports
     * count; the live comparison is what makes a ticket turn red twenty minutes after its deadline
     * on a site whose cron stopped running last Thursday.
     */
    private function applyBreaching(): void
    {
        $now = Db::prepareDateForDb(new \DateTime());

        $this->subQuery->andWhere(['or',
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
        ]);
    }

    /**
     * Sort the way an agent would pick tickets up: most urgent first, oldest first within that.
     *
     * A support queue ordered by date alone buries an urgent ticket raised this morning under
     * fifty routine ones raised yesterday.
     */
    public function orderByQueue(): static
    {
        // A CASE rather than MySQL's FIELD(), which Postgres does not have — and an ordering that
        // silently falls back to alphabetical would put "high" above "urgent" on exactly the
        // installs least likely to be tested.
        $this->orderBy([
            new Expression(
                "CASE [[csr_tickets.priority]] " .
                "WHEN 'urgent' THEN 0 WHEN 'high' THEN 1 WHEN 'normal' THEN 2 ELSE 3 END"
            ),
            'csr_tickets.submittedAt' => SORT_ASC,
        ]);

        return $this;
    }

    public static function tableName(): string
    {
        return Table::TICKETS;
    }
}
