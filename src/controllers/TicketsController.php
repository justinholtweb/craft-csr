<?php

declare(strict_types=1);

namespace justinholtweb\csr\controllers;

use Craft;
use craft\elements\User;
use craft\helpers\UrlHelper;
use craft\web\Controller;
use DateTime;
use justinholtweb\csr\elements\Ticket;
use justinholtweb\csr\enums\Priority;
use justinholtweb\csr\enums\TicketSource;
use justinholtweb\csr\models\Edition;
use justinholtweb\csr\Plugin;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * The desk's control panel screens.
 *
 * The ticket screen is a conversation, not a form. Everything an agent does — reply, note, move,
 * assign, escalate — is a small post to this controller and a redirect back, rather than one big
 * save: an agent who types a reply and then changes the status should not lose the reply because
 * the status select had a stale option in it.
 */
class TicketsController extends Controller
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requireCpRequest();
        $this->requirePermission(Plugin::PERMISSION_VIEW_TICKETS);

        return true;
    }

    public function actionIndex(): Response
    {
        $plugin = Plugin::getInstance();

        return $this->renderTemplate('csr/tickets/_index', [
            'counts' => $plugin->tickets->getQueueCounts(),
            'canManage' => Craft::$app->getUser()->checkPermission(Plugin::PERMISSION_MANAGE_TICKETS),
            'canExport' => Craft::$app->getUser()->checkPermission(Plugin::PERMISSION_EXPORT)
                && $plugin->exporter->isAvailable(),
        ]);
    }

    public function actionEdit(?int $ticketId = null, ?Ticket $ticket = null): Response
    {
        $plugin = Plugin::getInstance();

        if ($ticket === null) {
            if ($ticketId !== null) {
                /** @var Ticket|null $ticket */
                $ticket = Ticket::find()->id($ticketId)->isSpam(null)->one();

                if ($ticket === null) {
                    throw new NotFoundHttpException('Ticket not found.');
                }
            } else {
                $this->requirePermission(Plugin::PERMISSION_MANAGE_TICKETS);

                $ticket = new Ticket();
                $ticket->source = TicketSource::ControlPanel->value;
                $ticket->submittedSiteId = Craft::$app->getSites()->getCurrentSite()->id;
                $ticket->statusId = $plugin->statuses->getDefaultStatus()?->id;
                $ticket->priority = $plugin->getSettings()->getDefaultPriority()->value;
            }
        }

        return $this->renderTemplate('csr/tickets/_edit', [
            'ticket' => $ticket,
            'isNew' => $ticket->id === null,
            'statusOptions' => $plugin->statuses->getOptions(),
            'priorityOptions' => Priority::options(),
            'categoryOptions' => $plugin->categories->getOptions(true, true),
            'teamOptions' => $plugin->teams->getOptions(),
            'agentOptions' => $this->agentOptions($ticket),
            'cannedReplies' => $ticket->id !== null ? $plugin->cannedReplies->getRepliesFor($ticket) : [],
            'canManage' => Craft::$app->getUser()->checkPermission(Plugin::PERMISSION_MANAGE_TICKETS),
            'canDelete' => Craft::$app->getUser()->checkPermission(Plugin::PERMISSION_DELETE_TICKETS),
            'allowsSlas' => Edition::allowsSlas($plugin->isPro()),
            'allowsCanned' => Edition::allowsCannedReplies($plugin->isPro()),
            'allowsMerge' => Edition::allowsMerge($plugin->isPro()),
            'pigeonUrl' => $plugin->pigeon->getThreadUrl($ticket),
            'title' => $ticket->id !== null
                ? Craft::t('csr', 'Ticket #{number}', ['number' => $ticket->number])
                : Craft::t('csr', 'New ticket'),
        ]);
    }

    /** Create a ticket from inside the control panel — a phone call, or an email pasted in. */
    public function actionSave(): ?Response
    {
        $this->requirePostRequest();
        $this->requirePermission(Plugin::PERMISSION_MANAGE_TICKETS);

        $request = Craft::$app->getRequest();

        $ticket = Plugin::getInstance()->tickets->submit([
            'name' => $request->getBodyParam('requesterName'),
            'email' => (string)$request->getBodyParam('requesterEmail'),
            'subject' => (string)$request->getBodyParam('subject'),
            'message' => (string)$request->getBodyParam('message'),
            'categoryId' => $request->getBodyParam('categoryId'),
            'priority' => $request->getBodyParam('priority'),
            'source' => TicketSource::ControlPanel,
            // Raising a ticket *for* somebody in the control panel: the requester is whoever the
            // agent named, never the agent themselves.
            'requesterId' => $this->requesterIdFor((string)$request->getBodyParam('requesterEmail')),
        ]);

        if ($ticket->hasErrors()) {
            Craft::$app->getSession()->setError(Craft::t('csr', 'Couldn’t raise the ticket.'));
            Craft::$app->getUrlManager()->setRouteParams(['ticket' => $ticket]);

            return null;
        }

        if (($agentId = $request->getBodyParam('agentId')) !== null && $agentId !== '') {
            Plugin::getInstance()->tickets->assign($ticket, (int)$agentId);
        }

        return $this->asSuccess(
            Craft::t('csr', 'Ticket #{number} raised.', ['number' => $ticket->number]),
            ['ticket' => $ticket],
            UrlHelper::cpUrl('csr/tickets/' . $ticket->id),
        );
    }

    /** Post a reply or an internal note. */
    public function actionReply(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission(Plugin::PERMISSION_MANAGE_TICKETS);

        $request = Craft::$app->getRequest();
        $ticket = $this->ticketFromRequest();
        $plugin = Plugin::getInstance();

        $body = (string)$request->getBodyParam('body');
        $isNote = (bool)$request->getBodyParam('isNote');
        $cannedReplyId = $request->getBodyParam('cannedReplyId');

        $reply = $plugin->replies->post($ticket, [
            'body' => $body,
            'isNote' => $isNote,
            'byAgent' => true,
            'cannedReplyId' => $cannedReplyId ? (int)$cannedReplyId : null,
            'source' => 'cp',
            'notify' => !$isNote && (bool)$request->getBodyParam('notify', true),
        ]);

        if ($reply === null) {
            return $this->asFailure(Craft::t('csr', 'Write something first.'));
        }

        // The status the agent chose alongside the reply, applied afterwards so that the reply's
        // own transition (new → open) does not overwrite a deliberate choice.
        $statusId = $request->getBodyParam('statusId');

        if ($statusId !== null && $statusId !== '' && (int)$statusId !== $ticket->statusId) {
            $plugin->tickets->setStatus($ticket, (int)$statusId);
        }

        return $this->asSuccess(
            $isNote ? Craft::t('csr', 'Note added.') : Craft::t('csr', 'Reply sent.'),
            redirect: UrlHelper::cpUrl('csr/tickets/' . $ticket->id),
        );
    }

    /** Everything on the ticket sidebar: status, priority, category, agent, team. */
    public function actionUpdate(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission(Plugin::PERMISSION_MANAGE_TICKETS);

        $request = Craft::$app->getRequest();
        $ticket = $this->ticketFromRequest();
        $tickets = Plugin::getInstance()->tickets;

        if (($statusId = $request->getBodyParam('statusId')) !== null && $statusId !== '') {
            $tickets->setStatus($ticket, (int)$statusId);
        }

        if (($priority = $request->getBodyParam('priority')) !== null && $priority !== '') {
            $tickets->setPriority($ticket, (string)$priority);
        }

        if ($request->getBodyParam('categoryId') !== null) {
            $categoryId = $request->getBodyParam('categoryId');
            $tickets->setCategory($ticket, $categoryId !== '' ? (int)$categoryId : null);
        }

        if ($request->getBodyParam('teamId') !== null) {
            $teamId = $request->getBodyParam('teamId');
            $tickets->setTeam($ticket, $teamId !== '' ? (int)$teamId : null);
        }

        if ($request->getBodyParam('agentId') !== null) {
            $agentId = $request->getBodyParam('agentId');
            $tickets->assign($ticket, $agentId !== '' ? (int)$agentId : null);
        }

        $ticket->setFieldValuesFromRequest('fields');
        Craft::$app->getElements()->saveElement($ticket, false);

        return $this->asSuccess(
            Craft::t('csr', 'Ticket updated.'),
            redirect: UrlHelper::cpUrl('csr/tickets/' . $ticket->id),
        );
    }

    /** Take the ticket, in one click, without opening the sidebar. */
    public function actionClaim(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission(Plugin::PERMISSION_MANAGE_TICKETS);

        $ticket = $this->ticketFromRequest();
        Plugin::getInstance()->tickets->assign($ticket, Craft::$app->getUser()->getId());

        return $this->asSuccess(
            Craft::t('csr', 'Ticket #{number} is yours.', ['number' => $ticket->number]),
            redirect: UrlHelper::cpUrl('csr/tickets/' . $ticket->id),
        );
    }

    public function actionMerge(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission(Plugin::PERMISSION_MANAGE_TICKETS);

        $plugin = Plugin::getInstance();

        if (!Edition::allowsMerge($plugin->isPro())) {
            throw new ForbiddenHttpException('Merging tickets is a Pro feature.');
        }

        $source = $this->ticketFromRequest();
        $targetNumber = (int)Craft::$app->getRequest()->getRequiredBodyParam('targetNumber');
        $target = $plugin->tickets->getTicketByNumber($targetNumber);

        if ($target === null) {
            return $this->asFailure(Craft::t('csr', 'There is no ticket #{number}.', ['number' => $targetNumber]));
        }

        if (!$plugin->tickets->merge($source, $target)) {
            return $this->asFailure(Craft::t('csr', 'Couldn’t merge those tickets.'));
        }

        return $this->asSuccess(
            Craft::t('csr', 'Merged into ticket #{number}.', ['number' => $target->number]),
            redirect: UrlHelper::cpUrl('csr/tickets/' . $target->id),
        );
    }

    public function actionMarkSpam(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission(Plugin::PERMISSION_MANAGE_TICKETS);

        $ticket = $this->ticketFromRequest();
        $isSpam = (bool)Craft::$app->getRequest()->getBodyParam('isSpam', true);
        Plugin::getInstance()->tickets->markAsSpam($ticket, $isSpam);

        return $this->asSuccess(
            $isSpam ? Craft::t('csr', 'Marked as spam.') : Craft::t('csr', 'No longer marked as spam.'),
            redirect: UrlHelper::cpUrl('csr/tickets'),
        );
    }

    public function actionDelete(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission(Plugin::PERMISSION_DELETE_TICKETS);

        $ticket = $this->ticketFromRequest();
        Craft::$app->getElements()->deleteElement($ticket);

        return $this->asSuccess(Craft::t('csr', 'Ticket deleted.'), redirect: UrlHelper::cpUrl('csr/tickets'));
    }

    /**
     * Stream an attachment to an agent.
     *
     * Never the asset's own URL. A screenshot a stranger uploaded to a support ticket is somebody
     * else's data, and a guessable public path on a public volume is a breach waiting for
     * somebody to iterate the filenames.
     */
    public function actionDownload(int $attachmentId): Response
    {
        $attachment = Plugin::getInstance()->tickets->getAttachmentById($attachmentId);
        $asset = $attachment?->getAsset();

        if ($asset === null) {
            throw new NotFoundHttpException('That file is no longer here.');
        }

        return Craft::$app->getResponse()->sendStreamAsFile(
            $asset->getStream(),
            $attachment->filename,
            ['mimeType' => $asset->getMimeType(), 'fileSize' => $asset->size],
        );
    }

    public function actionExport(): Response
    {
        $this->requirePermission(Plugin::PERMISSION_EXPORT);

        $plugin = Plugin::getInstance();

        if (!$plugin->exporter->isAvailable()) {
            throw new ForbiddenHttpException('Exporting is a Pro feature.');
        }

        $request = Craft::$app->getRequest();
        $days = (int)$request->getQueryParam('days', 0);

        $query = Ticket::find()->isSpam(null)->limit(null);

        if ($days > 0) {
            $query->submittedAfter((new DateTime())->modify("-{$days} days"));
        }

        return $plugin->exporter->streamTickets($query);
    }

    private function ticketFromRequest(): Ticket
    {
        $ticketId = (int)Craft::$app->getRequest()->getRequiredBodyParam('ticketId');

        /** @var Ticket|null $ticket */
        $ticket = Ticket::find()->id($ticketId)->isSpam(null)->one();

        if ($ticket === null) {
            throw new NotFoundHttpException('Ticket not found.');
        }

        return $ticket;
    }

    /** @return array<string, string> */
    private function agentOptions(?Ticket $ticket): array
    {
        $options = ['' => Craft::t('csr', 'Unassigned')];

        foreach (Plugin::getInstance()->tickets->getAssignableAgents($ticket) as $id) {
            $user = Craft::$app->getUsers()->getUserById($id);

            if ($user !== null) {
                $options[(string)$id] = $user->getFriendlyName() ?: (string)$user->email;
            }
        }

        return $options;
    }

    /** If the address belongs to an account, tie the ticket to it. */
    private function requesterIdFor(string $email): ?int
    {
        if ($email === '') {
            return null;
        }

        $user = User::find()->email($email)->status(null)->one();

        return $user instanceof User ? (int)$user->id : null;
    }
}
