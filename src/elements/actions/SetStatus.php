<?php

declare(strict_types=1);

namespace justinholtweb\csr\elements\actions;

use Craft;
use craft\base\ElementAction;
use craft\elements\db\ElementQueryInterface;
use craft\helpers\Db;
use justinholtweb\csr\elements\Ticket;
use justinholtweb\csr\Plugin;

/**
 * Move a batch of tickets to a status from the element index.
 *
 * The bulk action a support inbox actually needs: after an outage is fixed, forty tickets are
 * resolved in one go. It goes through {@see \justinholtweb\csr\services\Tickets::setStatus()} per
 * element rather than issuing one `UPDATE`, which is slower and correct — the SLA clock, the
 * resolution stamps and the customer's email all hang off that method, and a bulk action that
 * skipped them would leave forty tickets marked resolved with no resolution date and nobody told.
 */
class SetStatus extends ElementAction
{
    public ?int $statusId = null;

    public function getTriggerLabel(): string
    {
        return Craft::t('csr', 'Set status');
    }

    protected function defineRules(): array
    {
        $rules = parent::defineRules();
        $rules[] = [['statusId'], 'required'];
        $rules[] = [['statusId'], 'integer'];

        return $rules;
    }

    public function getTriggerHtml(): ?string
    {
        $view = Craft::$app->getView();

        $view->registerJsWithVars(fn($type) => <<<JS
(() => {
    new Craft.ElementActionTrigger({
        type: $type,
        validateSelection: (selectedItems) => selectedItems.length > 0,
    });
})();
JS, [static::class]);

        return $view->renderTemplate('csr/_actions/set-status', [
            'statuses' => Plugin::getInstance()->statuses->getAllStatuses(),
        ]);
    }

    public function performAction(ElementQueryInterface $query): bool
    {
        $status = Plugin::getInstance()->statuses->getStatusById($this->statusId);

        if ($status === null) {
            $this->setMessage(Craft::t('csr', 'That status no longer exists.'));

            return false;
        }

        $tickets = Plugin::getInstance()->tickets;
        $moved = 0;
        $failed = 0;

        // `Db::each()` rather than `all()`: closing out an outage can be several thousand tickets,
        // and loading them all to change one column each is how a bulk action becomes a memory
        // limit on the one day the desk is busiest.
        foreach (Db::each($query) as $ticket) {
            if (!$ticket instanceof Ticket || $ticket->statusId === $status->id) {
                continue;
            }

            if ($tickets->setStatus($ticket, (int)$status->id)) {
                $moved++;
            } else {
                $failed++;
            }
        }

        if ($failed > 0) {
            $this->setMessage(Craft::t('csr', '{moved} moved to {status}; {failed} could not be.', [
                'moved' => $moved,
                'status' => $status->name,
                'failed' => $failed,
            ]));

            return $moved > 0;
        }

        $this->setMessage(Craft::t('csr', '{count, plural, =1{1 ticket} other{# tickets}} moved to {status}.', [
            'count' => $moved,
            'status' => $status->name,
        ]));

        return true;
    }
}
