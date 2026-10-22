<?php

declare(strict_types=1);

namespace App\MiniApp\UI;

use App\Bot\Application\ServiceManagementService;
use App\MiniApp\Security\MiniAppUser;
use App\Provisioning\Application\CustomerServiceFinder;
use App\Provisioning\Application\ServiceUsageSyncService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The owner's self-service actions on a service, the same ones the bot's service view offers and run by the bot's own
 * code (ServiceManagementService): the link, QR and configs are delivered into the user's chat with the bot, never in
 * this response. Refresh syncs usage from the panel (ServiceUsageSyncService) and the app reloads the section.
 * The service id is checked against the signed-in customer first (404 for anything not theirs); CSRF by the firewall.
 */
#[IsGranted(MiniAppUser::ROLE)]
final class MiniAppServiceActionController extends AbstractController
{
    public function __construct(
        private readonly CustomerServiceFinder $customerServices,
        private readonly ServiceManagementService $serviceManagement,
        private readonly ServiceUsageSyncService $usageSync,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('/app/api/services/{id}/{action}', name: 'miniapp_service_action', requirements: ['id' => '\d+', 'action' => 'send-link|send-qr|resend-config|refresh'], defaults: ['_miniapp_section' => 'services'], methods: ['POST'])]
    public function __invoke(Request $request, #[CurrentUser] MiniAppUser $user, int $id, string $action): JsonResponse
    {
        $service = $this->customerServices->ownedBy($user->customer(), $id);
        if (null === $service) {
            throw new NotFoundHttpException();
        }
        $locale = MiniAppLocale::fromRequest($request);
        // A private chat's id is the user's id: where the bot sends its answers.
        $chatId = $user->telegramUserId();

        switch ($action) {
            case 'send-link':
                $this->serviceManagement->sendSubscription($user->account, $id, $chatId, '');
                break;
            case 'send-qr':
                $this->serviceManagement->sendSubscriptionQr($user->account, $id, $chatId, '');
                break;
            case 'resend-config':
                $this->serviceManagement->resendConfig($user->account, $id, $chatId, '', false);
                break;
            case 'refresh':
                try {
                    $failed = $this->usageSync->syncOne($service)->isFailed();
                } catch (\Throwable) {
                    $failed = true;
                }

                return $this->answer($failed ? 'action.refresh_failed' : 'action.refreshed', $locale, $failed ? Response::HTTP_BAD_GATEWAY : Response::HTTP_OK);
        }

        return $this->answer('action.sent_to_chat', $locale);
    }

    private function answer(string $key, string $locale, int $status = Response::HTTP_OK): JsonResponse
    {
        $response = new JsonResponse(['message' => $this->translator->trans($key, [], 'miniapp', $locale)], $status);
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }
}
