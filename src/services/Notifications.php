<?php

declare(strict_types=1);

namespace justinholtweb\csr\services;

use Craft;
use craft\base\Component;
use craft\helpers\Queue;
use craft\mail\Message;
use justinholtweb\csr\elements\Ticket;
use justinholtweb\csr\jobs\SendTicketNotification;
use justinholtweb\csr\models\Reply;
use justinholtweb\csr\models\Status;
use justinholtweb\csr\Plugin;
use Throwable;

/**
 * The email CSR sends.
 *
 * Seven messages, all of them Craft **system messages**: editable under Settings → Email → System
 * Messages, translatable per site, and rendered through the site's own HTML email template. A
 * plugin that ships its own email editor is shipping a second, worse copy of a screen Craft
 * already has — and one the site's translators do not know about.
 *
 * The exception is the per-status letter. "We think this is fixed" and "we need more information"
 * are different letters, there is one per status, and they are part of the desk's design — so
 * those live in project config next to the status and deploy with it.
 *
 * **Everything is queued by default.** Sending mail inside the request means a slow SMTP server
 * shows up to somebody in trouble as a support form that hangs — and, worse, as a support form
 * some of them submit twice.
 */
class Notifications extends Component
{
    public const MESSAGE_RAISED_REQUESTER = 'csr_ticket_raised';
    public const MESSAGE_RAISED_AGENT = 'csr_ticket_raised_agent';
    public const MESSAGE_REPLY_REQUESTER = 'csr_ticket_reply';
    public const MESSAGE_REPLY_AGENT = 'csr_ticket_reply_agent';
    public const MESSAGE_STATUS = 'csr_ticket_status';
    public const MESSAGE_ASSIGNED = 'csr_ticket_assigned';
    public const MESSAGE_SLA = 'csr_sla_alert';
    public const MESSAGE_SATISFACTION = 'csr_satisfaction';

    // Entry points
    // -------------------------------------------------------------------------

    public function sendTicketRaised(Ticket $ticket): void
    {
        $settings = Plugin::getInstance()->getSettings();

        if (!$settings->notifyRequesterOnNew && !$settings->notifyAgentsOnNew) {
            return;
        }

        $this->dispatch($ticket, 'raised');
    }

    public function sendReply(Ticket $ticket, Reply $reply): void
    {
        $settings = Plugin::getInstance()->getSettings();

        // An agent's reply goes to the customer; a customer's reply goes to the desk. Sending
        // somebody a copy of what they just wrote is the commonest bug in helpdesk email.
        $wanted = $reply->byAgent ? $settings->notifyRequesterOnReply : $settings->notifyAgentOnReply;

        if (!$wanted) {
            return;
        }

        $this->dispatch($ticket, $reply->byAgent ? 'replyToRequester' : 'replyToAgent', ['replyId' => $reply->id]);
    }

    public function sendStatusChange(Ticket $ticket, Status $status): void
    {
        $this->dispatch($ticket, 'status', ['statusId' => $status->id]);
    }

    public function sendAssignment(Ticket $ticket): void
    {
        $this->dispatch($ticket, 'assigned');
    }

    public function sendBreach(Ticket $ticket, string $target): void
    {
        $this->dispatch($ticket, 'sla', ['target' => $target]);
    }

    public function sendEscalation(Ticket $ticket): void
    {
        $this->dispatch($ticket, 'sla', ['target' => 'escalation']);
    }

    public function sendSatisfactionRequest(Ticket $ticket): void
    {
        if (Plugin::getInstance()->getSettings()->satisfactionEnabled) {
            $this->dispatch($ticket, 'satisfaction');
        }
    }

    /** @param array<string, mixed> $context */
    private function dispatch(Ticket $ticket, string $kind, array $context = []): void
    {
        if ($ticket->id === null) {
            return;
        }

        if (Plugin::getInstance()->getSettings()->queueNotifications) {
            Queue::push(new SendTicketNotification([
                'ticketId' => $ticket->getCanonicalId(),
                'kind' => $kind,
                'context' => $context,
            ]));

            return;
        }

        $this->deliver($ticket, $kind, $context);
    }

    // Delivery
    // -------------------------------------------------------------------------

    /**
     * Actually send. Called from the queue job, or inline when queueing is off.
     *
     * @param array<string, mixed> $context
     */
    public function deliver(Ticket $ticket, string $kind, array $context = []): void
    {
        $settings = Plugin::getInstance()->getSettings();

        match ($kind) {
            'raised' => $this->deliverRaised($ticket, $settings->notifyRequesterOnNew, $settings->notifyAgentsOnNew),
            'replyToRequester' => $this->send(
                self::MESSAGE_REPLY_REQUESTER,
                [$ticket->requesterEmail],
                $this->variables($ticket, $context),
                $ticket,
            ),
            'replyToAgent' => $this->sendToDesk(self::MESSAGE_REPLY_AGENT, $ticket, $context),
            'status' => $this->deliverStatus($ticket, $context),
            'assigned' => $this->deliverAssignment($ticket),
            'sla' => $this->sendToDesk(self::MESSAGE_SLA, $ticket, $context),
            'satisfaction' => $this->send(
                self::MESSAGE_SATISFACTION,
                [$ticket->requesterEmail],
                $this->variables($ticket, $context),
                $ticket,
            ),
            default => null,
        };
    }

    private function deliverRaised(Ticket $ticket, bool $toRequester, bool $toAgents): void
    {
        if ($toRequester && $ticket->requesterEmail !== '') {
            $this->send(self::MESSAGE_RAISED_REQUESTER, [$ticket->requesterEmail], $this->variables($ticket), $ticket);
        }

        if ($toAgents) {
            $this->sendToDesk(self::MESSAGE_RAISED_AGENT, $ticket);
        }
    }

    /**
     * The per-status letter, or the generic one.
     *
     * A status with its own wording gets it; a status without falls back to the system message, so
     * turning "notify the requester" on for a status is one switch rather than a switch plus a
     * letter somebody has to write before it does anything.
     *
     * @param array<string, mixed> $context
     */
    private function deliverStatus(Ticket $ticket, array $context): void
    {
        $status = Plugin::getInstance()->statuses->getStatusById($context['statusId'] ?? null);

        if ($status === null || $ticket->requesterEmail === '') {
            return;
        }

        $variables = $this->variables($ticket, $context);

        if ($status->emailBody) {
            try {
                $subject = $this->renderStatusTemplate(
                    $status->emailSubject ?: Craft::t('csr', 'Ticket #{number}: {status}'),
                    $variables,
                );
                $body = $this->renderStatusTemplate($status->emailBody, $variables);
            } catch (Throwable $exception) {
                Craft::error(
                    "Could not render the “{$status->handle}” status email: " . $exception->getMessage(),
                    Plugin::LOG_CATEGORY,
                );

                return;
            }

            $this->sendRaw($ticket->requesterEmail, $subject, $body);

            return;
        }

        $this->send(self::MESSAGE_STATUS, [$ticket->requesterEmail], $variables, $ticket);
    }

    /**
     * Renders a status's own subject or body in Craft's Twig sandbox.
     *
     * Only admins edit statuses, but the letters travel through project config, and an unsandboxed
     * render hands whatever is in them the whole Craft API — `craft.app` included — at the moment
     * a ticket changes status. Sandboxed, they get the ticket variables and Craft's safe tags and
     * filters, which is all a letter needs.
     *
     * `renderSandboxedString()` arrived in Craft 5.9, and sandboxes only with `enableTwigSandbox`
     * on; CSR supports 5.4, so before 5.9 this is the plain render it always was.
     *
     * @param array<string, mixed> $variables
     */
    private function renderStatusTemplate(string $template, array $variables): string
    {
        $view = Craft::$app->getView();

        // @phpstan-ignore function.alreadyNarrowedType (absent before Craft 5.9; composer allows ^5.4)
        if (method_exists($view, 'renderSandboxedString')) {
            return $view->renderSandboxedString($template, $variables);
        }

        return $view->renderString($template, $variables);
    }

    private function deliverAssignment(Ticket $ticket): void
    {
        $agent = $ticket->getAgent();

        if ($agent !== null && $agent->email !== null) {
            $this->send(self::MESSAGE_ASSIGNED, [$agent->email], $this->variables($ticket), $ticket);
        }
    }

    /**
     * Everybody at the desk who should hear about this ticket.
     *
     * The assigned agent, the team it is routed to, and the standing recipients — deduplicated,
     * because somebody who is both the assigned agent and on the notification list should get one
     * email, not three.
     *
     * @param array<string, mixed> $context
     */
    private function sendToDesk(string $key, Ticket $ticket, array $context = []): void
    {
        $recipients = [];

        foreach (Plugin::getInstance()->getSettings()->getNotificationRecipients() as $address) {
            $recipients[$address] = true;
        }

        $agent = $ticket->getAgent();

        if ($agent?->email !== null) {
            $recipients[$agent->email] = true;
        }

        $team = $ticket->getTeam();

        if ($team !== null) {
            if ($team->email) {
                $recipients[$team->email] = true;
            }

            foreach ($team->getMembers() as $member) {
                if ($member->email !== null) {
                    $recipients[$member->email] = true;
                }
            }
        }

        if ($recipients === []) {
            Craft::warning(
                'A CSR ticket needs attention but there is nobody to tell — set notification recipients in the plugin settings.',
                Plugin::LOG_CATEGORY,
            );

            return;
        }

        $this->send($key, array_keys($recipients), $this->variables($ticket, $context), $ticket, true);
    }

    /**
     * @param string[] $to
     * @param array<string, mixed> $variables
     */
    private function send(string $key, array $to, array $variables, Ticket $ticket, bool $replyToRequester = false): bool
    {
        $to = array_values(array_filter($to, static fn(?string $address) => $address !== null && $address !== ''));

        if ($to === []) {
            return false;
        }

        try {
            // `composeFromKey()` renders the subject and body itself, at send time, through the
            // site's HTML email template — so rendering anything here would only be thrown away.
            $message = Craft::$app->getMailer()
                ->composeFromKey($key, $variables)
                ->setTo($to);

            // Reply-To rather than From: sending *as* the customer would fail SPF on most domains,
            // and a support notification in the spam folder is a ticket nobody answers. Hitting
            // reply then answers the person who wrote in, which is what everybody tries first.
            if ($replyToRequester && $ticket->requesterEmail !== '') {
                $message->setReplyTo([
                    $ticket->requesterEmail => $ticket->getRequesterLabel(),
                ]);
            }

            return $this->deliverMessage($message, implode(', ', $to));
        } catch (Throwable $exception) {
            Craft::error("Could not compose the “{$key}” email: " . $exception->getMessage(), Plugin::LOG_CATEGORY);

            return false;
        }
    }

    private function sendRaw(string $to, string $subject, string $body): bool
    {
        try {
            /** @var Message $message */
            $message = Craft::$app->getMailer()
                ->compose()
                ->setTo($to)
                ->setSubject($subject)
                ->setHtmlBody($body)
                ->setTextBody(strip_tags($body));

            return $this->deliverMessage($message, $to);
        } catch (Throwable $exception) {
            Craft::error("Could not send a CSR status email to {$to}: " . $exception->getMessage(), Plugin::LOG_CATEGORY);

            return false;
        }
    }

    private function deliverMessage(Message $message, string $to): bool
    {
        try {
            if ($message->send()) {
                return true;
            }

            Craft::warning("The mailer refused a CSR email to {$to}.", Plugin::LOG_CATEGORY);
        } catch (Throwable $exception) {
            Craft::error("Could not email {$to}: " . $exception->getMessage(), Plugin::LOG_CATEGORY);
        }

        return false;
    }

    /**
     * Everything a CSR email may refer to.
     *
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public function variables(Ticket $ticket, array $context = []): array
    {
        $reply = isset($context['replyId'])
            ? Plugin::getInstance()->replies->getReplyById((int)$context['replyId'])
            : null;

        $status = isset($context['statusId'])
            ? Plugin::getInstance()->statuses->getStatusById((int)$context['statusId'])
            : $ticket->getTicketStatus();

        return [
            'ticket' => $ticket,
            'reply' => $reply,
            'status' => $status,
            'number' => $ticket->number,
            'subject' => (string)$ticket->title,
            'message' => (string)$ticket->message,
            'requesterName' => $ticket->getGreetingName(),
            'requesterEmail' => $ticket->requesterEmail,
            'agentName' => (string)($ticket->getAgent()?->getFriendlyName() ?? Craft::t('csr', 'the support team')),
            'statusName' => (string)$status?->name,
            'category' => (string)$ticket->getCategory()?->name,
            'priority' => $ticket->getPriority()->label(),
            'replyBody' => $reply !== null ? strip_tags($reply->body) : '',
            'ticketUrl' => (string)$ticket->getPortalUrl(),
            'cpUrl' => (string)$ticket->getCpEditUrl(),
            'target' => (string)($context['target'] ?? ''),
            'organizationName' => Craft::$app->getSites()->getPrimarySite()->getName(),
        ];
    }

    /** Who at the desk would be told about a ticket right now — used by the settings screen. */
    public function getDeskRecipients(?Ticket $ticket = null): array
    {
        $recipients = Plugin::getInstance()->getSettings()->getNotificationRecipients();

        if ($ticket !== null) {
            $agent = $ticket->getAgent();

            if ($agent?->email !== null) {
                $recipients[] = $agent->email;
            }
        }

        return array_values(array_unique($recipients));
    }

    /**
     * The messages CSR adds to Settings → Email → System Messages.
     *
     * @return array<int, array<string, string>>
     */
    public static function systemMessages(): array
    {
        return [
            [
                'key' => self::MESSAGE_RAISED_REQUESTER,
                'heading' => Craft::t('csr', 'When somebody raises a ticket, send them:'),
                'subject' => Craft::t('csr', 'We’ve got your message — ticket #{number}'),
                'body' => Craft::t('csr', <<<'BODY'
Hi {requesterName},

Thanks for getting in touch about **{subject}**. Your ticket is number **{number}** and somebody
will read it.

You can see where it has got to, and add anything you have forgotten, here:
{ticketUrl}

{organizationName}
BODY),
            ],
            [
                'key' => self::MESSAGE_RAISED_AGENT,
                'heading' => Craft::t('csr', 'When a ticket arrives, send the desk:'),
                'subject' => Craft::t('csr', 'New ticket #{number}: {subject}'),
                'body' => Craft::t('csr', <<<'BODY'
**{requesterEmail}** has raised ticket **#{number}**.

- Subject: {subject}
- Category: {category}
- Priority: {priority}

{message}

Answer it here: {cpUrl}
BODY),
            ],
            [
                'key' => self::MESSAGE_REPLY_REQUESTER,
                'heading' => Craft::t('csr', 'When the desk replies, send the customer:'),
                'subject' => Craft::t('csr', 'Re: {subject} (ticket #{number})'),
                'body' => Craft::t('csr', <<<'BODY'
Hi {requesterName},

{agentName} has replied to your ticket:

{replyBody}

Reply to it here: {ticketUrl}

{organizationName}
BODY),
            ],
            [
                'key' => self::MESSAGE_REPLY_AGENT,
                'heading' => Craft::t('csr', 'When a customer replies, send the desk:'),
                'subject' => Craft::t('csr', 'Reply on ticket #{number}: {subject}'),
                'body' => Craft::t('csr', <<<'BODY'
**{requesterEmail}** has replied to ticket **#{number}**.

{replyBody}

Open it here: {cpUrl}
BODY),
            ],
            [
                'key' => self::MESSAGE_STATUS,
                'heading' => Craft::t('csr', 'When a ticket’s status changes, send the customer:'),
                'subject' => Craft::t('csr', 'Ticket #{number} is now {statusName}'),
                'body' => Craft::t('csr', <<<'BODY'
Hi {requesterName},

Your ticket about **{subject}** is now **{statusName}**.

If that is not the end of it, just reply here and we will pick it back up:
{ticketUrl}

{organizationName}
BODY),
            ],
            [
                'key' => self::MESSAGE_ASSIGNED,
                'heading' => Craft::t('csr', 'When a ticket is assigned to an agent, send them:'),
                'subject' => Craft::t('csr', 'Ticket #{number} is yours: {subject}'),
                'body' => Craft::t('csr', <<<'BODY'
Ticket **#{number}** has been assigned to you.

- Subject: {subject}
- From: {requesterEmail}
- Priority: {priority}

{cpUrl}
BODY),
            ],
            [
                'key' => self::MESSAGE_SLA,
                'heading' => Craft::t('csr', 'When a ticket misses its target or is escalated, send the desk:'),
                'subject' => Craft::t('csr', 'Ticket #{number} needs attention'),
                'body' => Craft::t('csr', <<<'BODY'
Ticket **#{number}** ({subject}) has passed its **{target}** target.

- From: {requesterEmail}
- Priority: {priority}
- Status: {statusName}

{cpUrl}
BODY),
            ],
            [
                'key' => self::MESSAGE_SATISFACTION,
                'heading' => Craft::t('csr', 'After a ticket is resolved, ask the customer:'),
                'subject' => Craft::t('csr', 'How did we do with ticket #{number}?'),
                'body' => Craft::t('csr', <<<'BODY'
Hi {requesterName},

We think we have sorted out **{subject}**. If we have, it would help us a great deal to know —
and if we have not, replying reopens the ticket.

{ticketUrl}

{organizationName}
BODY),
            ],
        ];
    }
}
