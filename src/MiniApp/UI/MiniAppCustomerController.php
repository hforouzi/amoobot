<?php

declare(strict_types=1);

namespace App\MiniApp\UI;

use App\Bot\Application\BotTextResolver;
use App\Entity\VpnService;
use App\MiniApp\Security\MiniAppUser;
use App\Provisioning\Application\CustomerServiceFinder;
use App\Provisioning\Application\TrafficAddonSettingsProvider;
use App\Shop\Application\Checkout\ServiceOrderCheckout;
use App\Shop\Application\SalesSettingsProvider;
use App\Subscription\Application\AppDownloadCatalog;
use App\Subscription\Application\ClientAppCatalog;
use App\Subscription\Application\SubscriptionPageUrl;
use App\Subscription\Application\SubscriptionPageView;
use App\Subscription\Application\SubscriptionPageViewFactory;
use App\Subscription\Domain\ClientPlatform;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The customer sections of the Mini App. Services come from CustomerServiceFinder (the bot's "My services" rule and
 * the ownership check) and are shown through SubscriptionPageViewFactory::summary(): status, plan, expiry and traffic,
 * never a link or config. Those stay on the subscription page, which the app opens, or come into the chat from the bot.
 */
#[IsGranted(MiniAppUser::ROLE)]
final class MiniAppCustomerController extends AbstractController
{
    public function __construct(
        private readonly MiniAppFragments $fragments,
        private readonly BotTextResolver $botTexts,
        private readonly CustomerServiceFinder $customerServices,
        private readonly SubscriptionPageViewFactory $viewFactory,
        private readonly SubscriptionPageUrl $pageUrl,
        private readonly ClientAppCatalog $clientApps,
        private readonly AppDownloadCatalog $downloads,
        private readonly ServiceOrderCheckout $serviceOrderCheckout,
        private readonly SalesSettingsProvider $salesSettings,
        private readonly TrafficAddonSettingsProvider $trafficAddonSettings,
        private readonly string $serviceNotifyTrafficThresholds = '80',
    ) {
    }

    #[Route('/app/view/home', name: 'miniapp_home', defaults: ['_miniapp_section' => 'home'], methods: ['GET'])]
    public function home(Request $request, #[CurrentUser] MiniAppUser $user): Response
    {
        $active = array_values(array_filter($this->cards($user), static fn (array $card): bool => $card['view']->isUsable()));
        $expiring = array_values(array_filter($active, static fn (array $card): bool => null !== $card['view']->daysLeft));
        usort($expiring, static fn (array $a, array $b): int => $a['view']->daysLeft <=> $b['view']->daysLeft);
        $lowTrafficPercent = $this->lowTrafficPercent();

        return $this->fragments->render($request, 'miniapp/view/home.html.twig', [
            'firstName' => (string) $user->account->getFirstName(),
            'activeCount' => count($active),
            'nearest' => $expiring[0] ?? null,
            'lowTraffic' => array_values(array_filter($active, static fn (array $card): bool => null !== $card['view']->usedPercent && $card['view']->usedPercent >= $lowTrafficPercent)),
        ]);
    }

    #[Route('/app/view/services', name: 'miniapp_services', defaults: ['_miniapp_section' => 'services'], methods: ['GET'])]
    public function services(Request $request, #[CurrentUser] MiniAppUser $user): Response
    {
        return $this->fragments->render($request, 'miniapp/view/services.html.twig', ['cards' => $this->cards($user)]);
    }

    #[Route('/app/view/services/{id}', name: 'miniapp_service', requirements: ['id' => '\d+'], defaults: ['_miniapp_section' => 'services'], methods: ['GET'])]
    public function service(Request $request, #[CurrentUser] MiniAppUser $user, int $id): Response
    {
        $service = $this->customerServices->ownedBy($user->customer(), $id);
        $view = null !== $service ? $this->viewFactory->summary($service) : null;
        if (null === $service || null === $view) {
            throw new NotFoundHttpException();
        }

        return $this->fragments->render($request, 'miniapp/view/service.html.twig', [
            'service' => $service,
            'view' => $view,
            'pageUrl' => $this->pageUrl->forService($service),
            // The same switches the bot's service view uses; the pages check them again.
            'canRenew' => $this->salesSettings->renewalsEnabled() && null !== $this->serviceOrderCheckout->renewalQuote($service),
            'canAddTraffic' => $this->salesSettings->addTrafficEnabled() && $this->trafficAddonSettings->canPurchase() && null !== $this->serviceOrderCheckout->addTrafficPlan($service),
        ]);
    }

    /** The subscription page's client apps and downloads, through the same partials (in_app mode). */
    #[Route('/app/view/apps', name: 'miniapp_apps', defaults: ['_miniapp_section' => 'apps'], methods: ['GET'])]
    public function apps(Request $request): Response
    {
        $platform = ClientPlatform::tryFrom((string) $request->query->get('platform')) ?? ClientPlatform::fromUserAgent($request->headers->get('User-Agent'));

        return $this->fragments->render($request, 'miniapp/view/apps.html.twig', [
            'view' => ['platform' => $platform, 'apps' => $this->clientApps->forCatalog($platform), 'downloads' => $this->downloads->forPage($platform), 'subscriptionUrl' => null],
            'platforms' => ClientPlatform::cases(),
        ]);
    }

    /** The bot's own support text (bot.support, editable in the admin's bot texts), so both say the same. */
    #[Route('/app/view/help', name: 'miniapp_help', defaults: ['_miniapp_section' => 'help'], methods: ['GET'])]
    public function help(Request $request): Response
    {
        return $this->fragments->render($request, 'miniapp/view/help.html.twig', [
            'supportText' => $this->botTexts->message('bot.support', [], MiniAppLocale::fromRequest($request)),
        ]);
    }

    /**
     * @return list<array{service: VpnService, view: SubscriptionPageView}>
     */
    private function cards(MiniAppUser $user): array
    {
        $cards = [];
        foreach ($this->customerServices->visibleTo($user->customer()) as $service) {
            $view = $this->viewFactory->summary($service);
            if (null !== $view) {
                $cards[] = ['service' => $service, 'view' => $view];
            }
        }

        return $cards;
    }

    /** "Low traffic" from the first traffic warning the bot sends (SERVICE_NOTIFY_TRAFFIC_THRESHOLDS). */
    private function lowTrafficPercent(): int
    {
        $thresholds = array_filter(array_map('intval', explode(',', $this->serviceNotifyTrafficThresholds)), static fn (int $p): bool => $p > 0 && $p <= 100);

        return [] === $thresholds ? 80 : min($thresholds);
    }
}
