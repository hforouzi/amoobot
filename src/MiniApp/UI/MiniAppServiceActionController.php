<?php

declare(strict_types=1);

namespace App\MiniApp\UI;

use App\MiniApp\Security\MiniAppUser;
use App\Module\Application\ProductModuleResolver;
use App\Module\Domain\CustomerActionInput;
use App\Module\Domain\CustomerActions;
use App\Provisioning\Application\CustomerServiceFinder;
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
 * The owner's self-service actions on a service, the same ones the bot's service view offers, run by the service's
 * product module (runCustomerAction(), PM1d; for VPN the bot's own code, ServiceManagementService): the link, QR and
 * configs are delivered into the user's chat with the bot, never in this response. Refresh syncs usage from the panel
 * (for VPN ServiceUsageSyncService::syncOne()) and the app reloads the section.
 * The service id is checked against the signed-in customer first (404 for anything not theirs); CSRF by the firewall.
 */
#[IsGranted(MiniAppUser::ROLE)]
final class MiniAppServiceActionController extends AbstractController
{
    public function __construct(
        private readonly CustomerServiceFinder $customerServices,
        private readonly ProductModuleResolver $productModules,
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
        $module = $this->productModules->forService($service);
        $input = new CustomerActionInput($user->account, $chatId, '');

        switch ($action) {
            case 'send-link':
                $module->runCustomerAction($service, CustomerActions::SEND_LINK, $input);
                break;
            case 'send-qr':
                $module->runCustomerAction($service, CustomerActions::SEND_QR, $input);
                break;
            case 'resend-config':
                $module->runCustomerAction($service, CustomerActions::RESEND_CONFIG, $input);
                break;
            case 'refresh':
                try {
                    $failed = $module->runCustomerAction($service, CustomerActions::REFRESH, $input)->failed;
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
