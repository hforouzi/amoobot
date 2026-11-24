<?php

declare(strict_types=1);

namespace App\Admin\UI;

use App\Admin\Application\Health\SystemHealth;
use App\Admin\Application\Health\TelegramWebhookStatus;
use App\Admin\UI\Shell\AdminUiMode;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * AD3 (docs/design/admin-redesign.md 4.4): the scheduled jobs page under Settings and the bot webhook's "check now".
 * Modern layout only (404 in classic). SETTINGS_MANAGE (AdminCrudPermissions), enforced by AdminPermissionListener.
 *
 * Loading a page never calls Telegram: "check now" is a GET confirm page whose form POSTs with a CSRF token, and only
 * that POST asks getWebhookInfo (ConfirmPages, as every admin action).
 */
final class SystemHealthController extends AbstractController
{
    use ConfirmPages;
    use TranslatedFlashes;

    public const WEBHOOK_CSRF = 'system_webhook_check';

    public function __construct(
        private readonly AdminUiMode $uiMode,
        private readonly SystemHealth $health,
    ) {
    }

    #[Route('/admin/settings/jobs', name: 'admin_system_jobs', methods: ['GET'])]
    public function jobs(): Response
    {
        $this->modernOnly();

        return $this->render('admin/modern/jobs.html.twig', ['health' => $this->health->summary()]);
    }

    #[Route('/admin/settings/jobs/webhook-check', name: 'admin_system_webhook_check', methods: ['GET', 'POST'])]
    public function checkWebhook(Request $request, TelegramWebhookStatus $webhook): Response
    {
        $this->modernOnly();
        if (!$request->isMethod('POST')) {
            return $this->confirmPage(
                'ad3.webhook.confirm_title',
                [['ad3.webhook.confirm_body']],
                self::WEBHOOK_CSRF,
                'ad3.webhook.check_now',
                $this->generateUrl('admin_system_jobs'),
            );
        }
        $token = $request->request->get('_token');
        if (!is_string($token) || !$this->isCsrfTokenValid(self::WEBHOOK_CSRF, $token)) {
            $this->flash('danger', 'flash.csrf_expired');

            return $this->redirectToRoute('admin_system_jobs');
        }

        $info = $webhook->refresh();
        $this->flash(TelegramWebhookStatus::STATE_OK === $webhook->state($info) ? 'success' : 'warning', 'flash.system.webhook_checked');

        return $this->redirectToRoute('admin_system_jobs');
    }

    private function modernOnly(): void
    {
        if (!$this->uiMode->isModern()) {
            throw $this->createNotFoundException();
        }
    }
}
