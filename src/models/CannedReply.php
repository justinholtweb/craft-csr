<?php

declare(strict_types=1);

namespace justinholtweb\csr\models;

use Craft;
use craft\base\Model;
use craft\helpers\UrlHelper;
use justinholtweb\csr\elements\Ticket;
use justinholtweb\csr\Plugin;

/**
 * A reply somebody has already written, well, once.
 *
 * The reason a support team wants these is not typing speed. It is that the fourth agent to answer
 * "how do I change my billing address" should give the same answer as the first — including the
 * caveat somebody added after a customer misread it.
 *
 * A database row rather than project config, because agents write these, and an agent writing a
 * better refund explanation on a Tuesday afternoon is not a deployment.
 *
 * ## Placeholders
 *
 * Deliberately not Twig. A canned reply is written by whoever is best at explaining things, not by
 * whoever is best at Twig, and a template error thrown while an agent is mid-reply loses the
 * reply. The placeholders are a fixed, documented list, and an unknown one is left alone rather
 * than blanked — `{orderTotal}` staying visible tells the author their placeholder was wrong;
 * silently emptying it tells them nothing until a customer asks what "your order came to  " means.
 */
class CannedReply extends Model
{
    public ?int $id = null;
    public string $name = '';

    /** What an agent types to insert it. `/refund`, not a menu three clicks deep. */
    public ?string $shortcut = null;

    public string $body = '';
    public ?int $categoryId = null;
    public ?int $teamId = null;
    public bool $enabled = true;
    public int $useCount = 0;
    public ?int $sortOrder = null;
    public ?string $uid = null;

    public function __toString(): string
    {
        return $this->name;
    }

    public function rules(): array
    {
        return [
            [['name', 'body'], 'required'],
            [['name'], 'string', 'max' => 255],
            [['shortcut'], 'string', 'max' => 64],
            [['shortcut'], 'match', 'pattern' => '/^\/?[a-z0-9][a-z0-9_-]*$/i'],
            [['categoryId', 'teamId', 'sortOrder'], 'integer'],
        ];
    }

    public function getCategory(): ?Category
    {
        return $this->categoryId !== null
            ? Plugin::getInstance()->categories->getCategoryById($this->categoryId)
            : null;
    }

    public function getTeam(): ?Team
    {
        return $this->teamId !== null
            ? Plugin::getInstance()->teams->getTeamById($this->teamId)
            : null;
    }

    /**
     * The reply with its placeholders filled in for this ticket.
     *
     * @return string
     */
    public function render(Ticket $ticket): string
    {
        $replacements = self::replacements($ticket);

        return preg_replace_callback(
            '/\{([a-zA-Z][a-zA-Z0-9_]*)\}/',
            static fn(array $match) => $replacements[$match[1]] ?? $match[0],
            $this->body,
        ) ?? $this->body;
    }

    /**
     * Everything a canned reply may refer to.
     *
     * @return array<string, string>
     */
    public static function replacements(Ticket $ticket): array
    {
        $agent = $ticket->getAgent();

        return [
            'ticketNumber' => (string)$ticket->number,
            'ticketSubject' => (string)$ticket->title,
            'ticketUrl' => (string)$ticket->getPortalUrl(),
            'ticketStatus' => (string)$ticket->getTicketStatus()?->name,
            'requesterName' => $ticket->getGreetingName(),
            'requesterEmail' => $ticket->requesterEmail,
            'requesterFirstName' => $ticket->getGreetingName(),
            'agentName' => (string)($agent?->getFriendlyName() ?? Craft::t('csr', 'the support team')),
            'agentFirstName' => (string)($agent->firstName ?? $agent?->getFriendlyName() ?? ''),
            'category' => (string)$ticket->getCategory()?->name,
            'siteName' => (string)Craft::$app->getSites()->getPrimarySite()->getName(),
        ];
    }

    /** The placeholder names, for the help text on the edit screen. */
    public static function placeholderNames(): array
    {
        return [
            'ticketNumber', 'ticketSubject', 'ticketUrl', 'ticketStatus',
            'requesterName', 'requesterFirstName', 'requesterEmail',
            'agentName', 'agentFirstName', 'category', 'siteName',
        ];
    }

    public function getCpEditUrl(): string
    {
        return UrlHelper::cpUrl('csr/canned-replies/' . ($this->id ?? 'new'));
    }
}
