<?php

declare(strict_types=1);

namespace App\Admin\UI;

use App\Broadcast\BroadcastAdministration;
use App\Entity\AdminUser;
use App\Entity\BroadcastMessage;
use App\Security\AdminAuditLogger;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * EasyAdmin broadcast actions (BROADCAST_SEND, AdminCrudPermissions): duplicate a finished broadcast as a new draft, and
 * retry its failed deliveries (refused while they failed because Telegram could not parse the same text). POST only
 * with a per-broadcast CSRF token (see BroadcastMessageCrudController), through the shared BroadcastAdministration.
 */
#[Route('/admin/broadcast')]
final class BroadcastActionsController extends AbstractController
{
    use TranslatedFlashes;

    public const CSRF_PREFIX = 'broadcast_action_';

    public function __construct(
        private readonly BroadcastAdministration $broadcastAdministration,
        private readonly AdminAuditLogger $auditLogger,
    ) {
    }

    #[Route('/{id}/duplicate', name: 'admin_broadcast_duplicate', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function duplicate(BroadcastMessage $broadcast, Request $request): RedirectResponse
    {
        if (!$this->validToken($broadcast, $request)) {
            return $this->invalidToken($broadcast);
        }

        $user = $this->getUser();
        $copy = $this->broadcastAdministration->duplicate($broadcast, $user instanceof AdminUser ? $user : null);
        if (null === $copy) {
            $this->flash('danger', 'flash.broadcast.not_duplicable');

            return $this->detail($broadcast);
        }
        $this->auditLogger->record('broadcast.duplicate', 'BroadcastMessage', $copy->getId(), ['from' => $broadcast->getId(), 'title' => $copy->getTitle()]);
        $this->flash('success', 'flash.broadcast.duplicated');

        return $this->redirectToRoute('admin_broadcast_message_edit', ['entityId' => $copy->getId()]);
    }

    #[Route('/{id}/retry-failed', name: 'admin_broadcast_retry_failed', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function retryFailed(BroadcastMessage $broadcast, Request $request): RedirectResponse
    {
        if (!$this->validToken($broadcast, $request)) {
            return $this->invalidToken($broadcast);
        }

        $summary = $this->broadcastAdministration->retryFailed($broadcast);
        if (null === $summary) {
            $this->flash('danger', 'flash.broadcast.retry_format_blocked');

            return $this->detail($broadcast);
        }
        $this->auditLogger->record('broadcast.retry_failed', 'BroadcastMessage', $broadcast->getId(), ['sent' => $summary->sent, 'failed' => $summary->failed, 'pending' => $summary->pending]);
        $this->flash('success', 'flash.broadcast.retry_sent', ['%sent%' => $summary->sent, '%failed%' => $summary->failed]);

        return $this->detail($broadcast);
    }

    private function validToken(BroadcastMessage $broadcast, Request $request): bool
    {
        $token = $request->request->get('_token') ?? $request->query->get('_token');

        return is_string($token) && $this->isCsrfTokenValid(self::CSRF_PREFIX.$broadcast->getId(), $token);
    }

    private function invalidToken(BroadcastMessage $broadcast): RedirectResponse
    {
        $this->flash('danger', 'flash.csrf_expired');

        return $this->detail($broadcast);
    }

    private function detail(BroadcastMessage $broadcast): RedirectResponse
    {
        return $this->redirectToRoute('admin_broadcast_message_detail', ['entityId' => $broadcast->getId()]);
    }
}
