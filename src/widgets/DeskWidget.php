<?php

declare(strict_types=1);

namespace justinholtweb\csr\widgets;

use Craft;
use craft\base\Widget;
use justinholtweb\csr\elements\Ticket;
use justinholtweb\csr\Plugin;

/**
 * What the desk looks like right now, on the dashboard.
 *
 * Four numbers and the oldest unanswered tickets. Deliberately not a chart: somebody opening the
 * control panel in the morning wants to know whether anything is on fire, and a sparkline of last
 * month's volume does not answer that.
 */
class DeskWidget extends Widget
{
    /** How many unanswered tickets to list under the counts. */
    public int $limit = 5;

    /** Only the current user's tickets, for an agent rather than a manager. */
    public bool $mineOnly = false;

    public static function displayName(): string
    {
        return Craft::t('csr', 'Support desk');
    }

    public static function icon(): ?string
    {
        return 'comments';
    }

    public static function isSelectable(): bool
    {
        return parent::isSelectable()
            && Craft::$app->getUser()->checkPermission(Plugin::PERMISSION_VIEW_TICKETS);
    }

    public function getTitle(): ?string
    {
        return $this->mineOnly
            ? Craft::t('csr', 'My tickets')
            : Craft::t('csr', 'Support desk');
    }

    public function getBodyHtml(): ?string
    {
        $plugin = Plugin::getInstance();
        $userId = Craft::$app->getUser()->getId();

        $query = Ticket::find()
            ->openOnly()
            ->awaitingFirstResponse()
            ->orderByQueue()
            ->limit($this->limit);

        if ($this->mineOnly && $userId !== null) {
            $query->agentId($userId);
        }

        return Craft::$app->getView()->renderTemplate('csr/_widgets/desk', [
            'counts' => $plugin->tickets->getQueueCounts($this->mineOnly ? $userId : null),
            'tickets' => $query->all(),
            'mineOnly' => $this->mineOnly,
        ]);
    }

    public function getSettingsHtml(): ?string
    {
        return Craft::$app->getView()->renderTemplate('csr/_widgets/desk-settings', [
            'widget' => $this,
        ]);
    }

    protected function defineRules(): array
    {
        $rules = parent::defineRules();
        $rules[] = [['limit'], 'integer', 'min' => 1, 'max' => 20];

        return $rules;
    }
}
