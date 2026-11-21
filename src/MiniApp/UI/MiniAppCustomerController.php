<?php

declare(strict_types=1);

namespace App\MiniApp\UI;

use App\Bot\Application\BotTextResolver;
use App\Branding\SupportLinkSettings;
use App\Entity\VpnService;
use App\MiniApp\Guide\MiniAppGuide;
use App\MiniApp\Security\MiniAppUser;
use App\Module\Application\ProductModuleResolver;
use App\Module\Domain\CustomerActions;
use App\Provisioning\Application\CustomerServiceFinder;
use App\Subscription\Application\AppDownloadCatalog;
use App\Subscription\Application\ClientAppCatalog;
use App\Subscription\Application\SubscriptionPageView;
use App\Subscription\Domain\ClientPlatform;
use App\Wallet\Application\WalletService;
use App\Wallet\Application\WalletSettings;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The customer sections of the Mini App. Services come from CustomerServiceFinder (the bot's "My services" rule and
 * the ownership check) and are shown through their product module's customerView() (for VPN
 * SubscriptionPageViewFactory::summary(): status, plan, expiry and traffic, never a link or config) and
 * customerActions(). Links and configs stay on the subscription page, which the app opens, or come into the chat from
 * the bot.
 */
#[IsGranted(MiniAppUser::ROLE)]
final class MiniAppCustomerController extends AbstractController
{
    public function __construct(
        private readonly MiniAppFragments $fragments,
        private readonly BotTextResolver $botTexts,
        private readonly CustomerServiceFinder $customerServices,
        private readonly ProductModuleResolver $productModules,
        private readonly ClientAppCatalog $clientApps,
        private readonly AppDownloadCatalog $downloads,
        private readonly WalletSettings $walletSettings,
        private readonly WalletService $wallets,
        private readonly MiniAppGuide $guide,
        private readonly SupportLinkSettings $supportLink,
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
        // The wallet card, only while the wallet is on: one lookup by user, never created here (no wallet = 0).
        $walletEnabled = $this->walletSettings->enabled();
        $wallet = $walletEnabled ? $this->wallets->find($user->customer()) : null;

        return $this->fragments->render($request, 'miniapp/view/home.html.twig', [
            'firstName' => (string) $user->account->getFirstName(),
            'activeCount' => count($active),
            'nearest' => $expiring[0] ?? null,
            'lowTraffic' => array_values(array_filter($active, static fn (array $card): bool => null !== $card['view']->usedPercent && $card['view']->usedPercent >= $lowTrafficPercent)),
            'walletEnabled' => $walletEnabled,
            'walletBalance' => $wallet?->getBalance() ?? 0,
            'walletFrozen' => $wallet?->isFrozen() ?? false,
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
        if (null === $service) {
            throw new NotFoundHttpException();
        }
        $module = $this->productModules->forService($service);
        $customerView = $module->customerView($service);
        $view = $customerView->summary();
        if (null === $view) {
            throw new NotFoundHttpException();
        }
        // The page link, renewal and extra traffic: the module's rules for this view (the pages check them again).
        $actions = $module->customerActions($service, CustomerActions::CHANNEL_MINIAPP);

        return $this->fragments->render($request, 'miniapp/view/service.html.twig', [
            'service' => $service,
            'view' => $view,
            'pageUrl' => $actions->pageUrl,
            // A plan with several inbounds (switch on): "Servers: N", never which ones; null for every other service.
            'servers' => $customerView->servers(),
            'canRenew' => $actions->has(CustomerActions::RENEW),
            'canAddTraffic' => $actions->has(CustomerActions::ADD_TRAFFIC),
        ]);
    }

    /**
     * The subscription page inside the Mini App, for the signed-in owner only (not the public /s/{token} link): the
     * same view (the module's customerView(), for VPN SubscriptionPageViewFactory::build()) and partials, so it carries
     * the customer's own subscription link,
     * QR code and configs. The only /app response that does; one-tap imports go through the https bridge.
     */
    #[Route('/app/view/services/{id}/subscription', name: 'miniapp_subscription', requirements: ['id' => '\d+'], defaults: ['_miniapp_section' => 'services'], methods: ['GET'])]
    public function subscription(Request $request, #[CurrentUser] MiniAppUser $user, int $id): Response
    {
        $service = $this->customerServices->ownedBy($user->customer(), $id);
        $platform = ClientPlatform::tryFrom((string) $request->query->get('platform')) ?? ClientPlatform::fromUserAgent($request->headers->get('User-Agent'));
        $view = null !== $service ? $this->productModules->forService($service)->customerView($service)->page($platform) : null;
        if (null === $service || null === $view) {
            throw new NotFoundHttpException();
        }

        return $this->fragments->render($request, 'miniapp/view/subscription.html.twig', [
            'service' => $service,
            'view' => $view,
            'platforms' => ClientPlatform::cases(),
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

    /**
     * The customer guide (MiniAppGuide: the topics the shop's current state allows, the same for every customer) and
     * the bot's own support text (bot.support, editable in the admin's bot texts, so both say the same), escaped as
     * text whatever it holds, and the "Message support" button when the admin set a support chat (SupportLinkSettings:
     * only a validated https://t.me/<username>, never anything from the guide texts). ?topic=<slug> opens that topic's
     * card; any other value opens none. The "connect" topic lists the client apps of the device: ?platform=<value> (the
     * customer's switch), else the one the User-Agent suggests, else none chosen yet.
     */
    #[Route('/app/view/help', name: 'miniapp_help', defaults: ['_miniapp_section' => 'help'], methods: ['GET'])]
    public function help(Request $request): Response
    {
        $locale = MiniAppLocale::fromRequest($request);
        $chosen = $request->query->all()['platform'] ?? null;
        $platform = (is_string($chosen) ? ClientPlatform::tryFrom($chosen) : null) ?? ClientPlatform::fromUserAgent($request->headers->get('User-Agent'));
        $topics = $this->guide->topics($locale, null, $platform);

        return $this->fragments->render($request, 'miniapp/view/help.html.twig', [
            'topics' => $topics,
            'openTopic' => MiniAppGuide::requestedTopic($request->query->all()['topic'] ?? null, $topics),
            'supportText' => $this->guide->plain($this->botTexts->message('bot.support', [], $locale)),
            'supportUrl' => $this->supportLink->url(),
        ]);
    }

    /**
     * @return list<array{service: VpnService, view: SubscriptionPageView}>
     */
    private function cards(MiniAppUser $user): array
    {
        $cards = [];
        foreach ($this->customerServices->visibleTo($user->customer()) as $service) {
            $view = $this->productModules->forService($service)->customerView($service)->summary();
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
