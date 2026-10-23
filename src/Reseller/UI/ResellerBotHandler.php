<?php

declare(strict_types=1);

namespace App\Reseller\UI;

use App\Bot\Application\BotTextResolver;
use App\Bot\Application\MembershipGatePrompt;
use App\Bot\Application\TelegramChannelMembershipGate;
use App\Bot\Application\TelegramKeyboardFactory;
use App\Bot\Infrastructure\TelegramApiClient;
use App\Entity\Reseller;
use App\Entity\TelegramAccount;
use App\Payment\Application\Settlement\SettlementOutcome;
use App\Payment\Application\Settlement\SettlementResult;
use App\Provisioning\Application\TrafficAddonSettingsProvider;
use App\Reseller\Application\ResellerCheckout;
use App\Reseller\Domain\ResellerException;
use App\Reseller\Domain\WholesaleQuote;
use App\Shop\Application\Checkout\PlanCatalog;
use App\Wallet\Application\WalletService;
use App\Wallet\Domain\WalletException;
use Psr\Log\LoggerInterface;

/**
 * The reseller menu in the bot (S1, minimal): balance, buying a new service at wholesale (fixed plans; a customizable
 * plan needs choices the Mini App will ask for), the services he sold, and renewing / adding traffic to them at
 * wholesale. Shown only to an active reseller while reseller.enabled is on; every callback checks that again.
 *
 * Callbacks: reseller, rs_new, rs_plan:{planId} (confirm), rs_buy:{planId}, rs_sold:{page}, rs_svc:{serviceId},
 * rs_renew:{id} (confirm), rs_renew_ok:{id}, rs_tr:{id} (choose GB), rs_tr_gb:{id}:{gb} (confirm), rs_tr_ok:{id}:{gb}.
 * Every price is quoted on the server at that moment (ResellerCheckout / ResellerPricing); the confirm step shows it
 * and the purchase quotes again under the reseller lock. A double tap is harmless: each purchase is its own order
 * paid in one call, and the wallet cannot be overdrawn.
 */
final class ResellerBotHandler
{
    public const SOLD_PER_PAGE = 8;
    private const PREFIXES = ['rs_plan:', 'rs_buy:', 'rs_sold:', 'rs_svc:', 'rs_renew:', 'rs_renew_ok:', 'rs_tr:', 'rs_tr_gb:', 'rs_tr_ok:'];
    private const TRAFFIC_CHOICES = [10, 20, 50, 100];

    public function __construct(
        private readonly TelegramApiClient $telegram,
        private readonly BotTextResolver $texts,
        private readonly TelegramKeyboardFactory $keyboards,
        private readonly ResellerCheckout $checkout,
        private readonly PlanCatalog $planCatalog,
        private readonly WalletService $wallets,
        private readonly TrafficAddonSettingsProvider $trafficAddonSettings,
        private readonly MembershipGatePrompt $membershipGate,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function supports(string $data): bool
    {
        if (in_array($data, ['reseller', 'rs_new'], true)) {
            return true;
        }
        foreach (self::PREFIXES as $prefix) {
            if (str_starts_with($data, $prefix)) {
                return true;
            }
        }

        return false;
    }

    public function handle(TelegramAccount $account, string $chatId, string $callbackId, string $data): void
    {
        $reseller = $this->checkout->resellerFor($account->getUser());
        if (!$reseller instanceof Reseller) {
            $this->popup($chatId, $callbackId, $this->texts->message('reseller.unavailable'));

            return;
        }
        $parts = explode(':', $data);
        $first = isset($parts[1]) && ctype_digit($parts[1]) ? (int) $parts[1] : 0;
        $second = isset($parts[2]) && ctype_digit($parts[2]) ? (int) $parts[2] : 0;

        match ($parts[0]) {
            'reseller' => $this->showMenu($account, $chatId, $callbackId),
            'rs_new' => $this->showPlans($account, $chatId, $callbackId),
            'rs_plan' => $this->confirmNew($account, $chatId, $callbackId, $first),
            'rs_buy' => $this->buyNew($account, $chatId, $callbackId, $first),
            'rs_sold' => $this->showSold($reseller, $chatId, $callbackId, max(1, $first)),
            'rs_svc' => $this->showService($account, $chatId, $callbackId, $first),
            'rs_renew' => $this->confirmRenew($account, $chatId, $callbackId, $first),
            'rs_renew_ok' => $this->settle($account, $chatId, $callbackId, fn (): SettlementResult => $this->checkout->renew($account->getUser(), $first)),
            'rs_tr' => $this->chooseTraffic($account, $chatId, $callbackId, $first),
            'rs_tr_gb' => $this->confirmTraffic($account, $chatId, $callbackId, $first, $second),
            'rs_tr_ok' => $this->settle($account, $chatId, $callbackId, fn (): SettlementResult => $this->checkout->addTraffic($account->getUser(), $first, $second)),
            default => $this->ack($callbackId),
        };
    }

    /** The menu: the main menu's reseller button (no callback) or the reseller callback. */
    public function showMenu(TelegramAccount $account, string $chatId, ?string $callbackId): void
    {
        $reseller = $this->checkout->resellerFor($account->getUser());
        if (!$reseller instanceof Reseller) {
            $this->popup($chatId, $callbackId, $this->texts->message('reseller.unavailable'));

            return;
        }
        $this->ack($callbackId);
        $wallet = $this->wallets->find($account->getUser());
        $lines = [$this->texts->message('reseller.menu', ['reseller' => ['balance' => number_format($wallet?->getBalance() ?? 0)]])];
        if (null !== $wallet && $wallet->getCreditLimit() > 0) {
            $lines[] = $this->texts->message('wallet.overview_credit', ['wallet' => ['creditLimit' => number_format($wallet->getCreditLimit()), 'available' => number_format($wallet->getAvailable())]]);
        }
        if (true === $wallet?->isFrozen()) {
            $lines[] = $this->texts->message('wallet.overview_frozen');
        }
        $remaining = $this->checkout->remainingToday($reseller);
        if (null !== $remaining) {
            $lines[] = $this->texts->message('reseller.menu_daily', ['reseller' => ['remaining' => number_format($remaining)]]);
        }
        $this->telegram->sendMessage($chatId, implode("\n\n", $lines), ['inline_keyboard' => [
            [['text' => $this->texts->button('button.reseller.new_service'), 'callback_data' => 'rs_new']],
            [['text' => $this->texts->button('button.reseller.sold_services'), 'callback_data' => 'rs_sold:1']],
            [['text' => $this->texts->button('button.main.wallet'), 'callback_data' => 'wallet']],
            [['text' => $this->texts->button('button.common.back_to_main_menu'), 'callback_data' => 'main_menu']],
        ]]);
    }

    private function showPlans(TelegramAccount $account, string $chatId, string $callbackId): void
    {
        $buttons = [];
        foreach ($this->planCatalog->forSale() as $plan) {
            if ($plan->isCustomizable()) {
                continue;
            }
            try {
                [, , $quote] = $this->checkout->quoteNewService($account->getUser(), (int) $plan->getId());
            } catch (ResellerException) {
                continue;
            }
            $buttons[] = ['text' => sprintf('%s — %s', $plan->getTitle(), number_format($quote->wholesaleAmount)), 'callback_data' => 'rs_plan:'.$plan->getId()];
        }
        if ([] === $buttons) {
            $this->popup($chatId, $callbackId, $this->texts->message('reseller.plans_empty'));

            return;
        }
        $this->ack($callbackId);
        $rows = $this->keyboards->inlineKeyboardRows($buttons, 1);
        $rows[] = [['text' => $this->texts->button('button.reseller.back'), 'callback_data' => 'reseller']];
        $this->telegram->sendMessage($chatId, $this->texts->message('reseller.plans_title'), ['inline_keyboard' => $rows]);
    }

    private function confirmNew(TelegramAccount $account, string $chatId, string $callbackId, int $planId): void
    {
        try {
            [, $plan, $quote] = $this->checkout->quoteNewService($account->getUser(), $planId);
        } catch (ResellerException $e) {
            $this->popup($chatId, $callbackId, $this->refusal($e->error));

            return;
        }
        $this->ack($callbackId);
        $this->telegram->sendMessage($chatId, $this->texts->message('reseller.confirm_new', ['plan' => ['title' => $plan->getTitle()]] + $this->quoteVariables($account, $quote)), ['inline_keyboard' => [
            [['text' => $this->texts->button('button.reseller.confirm'), 'callback_data' => 'rs_buy:'.$planId]],
            [['text' => $this->texts->button('button.reseller.cancel'), 'callback_data' => 'reseller']],
        ]]);
    }

    private function buyNew(TelegramAccount $account, string $chatId, string $callbackId, int $planId): void
    {
        // The same required-channels rule as every other way to buy.
        if (!$this->membershipGate->allowed($account, $chatId, $callbackId, TelegramChannelMembershipGate::CONTEXT_PURCHASE)) {
            return;
        }
        $this->settle($account, $chatId, $callbackId, fn (): SettlementResult => $this->checkout->buyNewService($account->getUser(), $planId));
    }

    private function showSold(Reseller $reseller, string $chatId, string $callbackId, int $page): void
    {
        $result = $this->checkout->soldServices($reseller, $page, self::SOLD_PER_PAGE);
        if ([] === $result['items']) {
            $this->popup($chatId, $callbackId, $this->texts->message('reseller.sold_empty'));

            return;
        }
        $this->ack($callbackId);
        $rows = [];
        foreach ($result['items'] as $service) {
            $rows[] = [['text' => sprintf('#%d %s (%s)', (int) $service->getId(), (string) $service->getUsername(), $this->texts->message('reseller.status.'.$service->getStatus())), 'callback_data' => 'rs_svc:'.$service->getId()]];
        }
        if ($result['hasNext']) {
            $rows[] = [['text' => $this->texts->button('button.reseller.next_page'), 'callback_data' => 'rs_sold:'.($page + 1)]];
        }
        $rows[] = [['text' => $this->texts->button('button.reseller.back'), 'callback_data' => 'reseller']];
        $this->telegram->sendMessage($chatId, $this->texts->message('reseller.sold_title', ['page' => $page]), ['inline_keyboard' => $rows]);
    }

    private function showService(TelegramAccount $account, string $chatId, string $callbackId, int $serviceId): void
    {
        try {
            $service = $this->checkout->findSoldService($account->getUser(), $serviceId);
        } catch (ResellerException $e) {
            $this->popup($chatId, $callbackId, $this->refusal($e->error));

            return;
        }
        $this->ack($callbackId);
        $this->telegram->sendMessage($chatId, $this->texts->message('reseller.service', ['service' => [
            'id' => (int) $service->getId(),
            'username' => (string) $service->getUsername(),
            'status' => $this->texts->message('reseller.status.'.$service->getStatus()),
            'expiresAt' => $service->getExpiresAt()?->format('Y-m-d') ?? '∞',
            'traffic' => sprintf('%s / %s GB', self::gb($service->getTrafficUsedBytes()), null === $service->getTrafficLimitBytes() ? '∞' : self::gb($service->getTrafficLimitBytes())),
        ]]), ['inline_keyboard' => [
            [['text' => $this->texts->button('button.reseller.renew'), 'callback_data' => 'rs_renew:'.$serviceId], ['text' => $this->texts->button('button.reseller.add_traffic'), 'callback_data' => 'rs_tr:'.$serviceId]],
            [['text' => $this->texts->button('button.reseller.back'), 'callback_data' => 'rs_sold:1']],
        ]]);
    }

    private function confirmRenew(TelegramAccount $account, string $chatId, string $callbackId, int $serviceId): void
    {
        try {
            [, $service, $quote] = $this->checkout->quoteRenewal($account->getUser(), $serviceId);
        } catch (ResellerException $e) {
            $this->popup($chatId, $callbackId, $this->refusal($e->error));

            return;
        }
        $this->ack($callbackId);
        $this->telegram->sendMessage($chatId, $this->texts->message('reseller.confirm_renew', ['service' => ['id' => (int) $service->getId(), 'username' => (string) $service->getUsername()]] + $this->quoteVariables($account, $quote)), ['inline_keyboard' => [
            [['text' => $this->texts->button('button.reseller.confirm'), 'callback_data' => 'rs_renew_ok:'.$serviceId]],
            [['text' => $this->texts->button('button.reseller.cancel'), 'callback_data' => 'rs_svc:'.$serviceId]],
        ]]);
    }

    private function chooseTraffic(TelegramAccount $account, string $chatId, string $callbackId, int $serviceId): void
    {
        $min = $this->trafficAddonSettings->minGb();
        $max = $this->trafficAddonSettings->maxGb();
        $choices = array_values(array_unique(array_filter([$min, ...self::TRAFFIC_CHOICES], static fn (int $gb): bool => $gb >= $min && $gb <= $max)));
        sort($choices);
        try {
            $this->checkout->quoteAddTraffic($account->getUser(), $serviceId, $choices[0] ?? 0);
        } catch (ResellerException $e) {
            $this->popup($chatId, $callbackId, $this->refusal($e->error));

            return;
        }
        $this->ack($callbackId);
        $buttons = array_map(static fn (int $gb): array => ['text' => $gb.' GB', 'callback_data' => sprintf('rs_tr_gb:%d:%d', $serviceId, $gb)], $choices);
        $rows = $this->keyboards->inlineKeyboardRows($buttons, 2);
        $rows[] = [['text' => $this->texts->button('button.reseller.back'), 'callback_data' => 'rs_svc:'.$serviceId]];
        $this->telegram->sendMessage($chatId, $this->texts->message('reseller.choose_traffic'), ['inline_keyboard' => $rows]);
    }

    private function confirmTraffic(TelegramAccount $account, string $chatId, string $callbackId, int $serviceId, int $trafficGb): void
    {
        try {
            [, $service, $quote] = $this->checkout->quoteAddTraffic($account->getUser(), $serviceId, $trafficGb);
        } catch (ResellerException $e) {
            $this->popup($chatId, $callbackId, $this->refusal($e->error));

            return;
        }
        $this->ack($callbackId);
        $this->telegram->sendMessage($chatId, $this->texts->message('reseller.confirm_traffic', ['service' => ['id' => (int) $service->getId(), 'username' => (string) $service->getUsername(), 'trafficGb' => $trafficGb]] + $this->quoteVariables($account, $quote)), ['inline_keyboard' => [
            [['text' => $this->texts->button('button.reseller.confirm'), 'callback_data' => sprintf('rs_tr_ok:%d:%d', $serviceId, $trafficGb)]],
            [['text' => $this->texts->button('button.reseller.cancel'), 'callback_data' => 'rs_svc:'.$serviceId]],
        ]]);
    }

    /**
     * Runs one purchase. Settlement already sent the service / delay / review message to the reseller; this adds what
     * the wallet paid and what is left.
     *
     * @param \Closure(): SettlementResult $purchase
     */
    private function settle(TelegramAccount $account, string $chatId, string $callbackId, \Closure $purchase): void
    {
        try {
            $result = $purchase();
        } catch (ResellerException|WalletException $e) {
            $this->popup($chatId, $callbackId, $this->refusal($e->error));

            return;
        } catch (\Throwable $e) {
            $this->logger->error('Bot reseller purchase failed.', ['telegram_id' => $account->getTelegramId(), 'error' => $e->getMessage()]);
            $this->popup($chatId, $callbackId, $this->texts->message('reseller.failed'));

            return;
        }
        if (!in_array($result->outcome, [SettlementOutcome::CONFIRMED, SettlementOutcome::PROVISIONING_FAILED, SettlementOutcome::NEEDS_REVIEW], true)) {
            $this->popup($chatId, $callbackId, $this->texts->message('reseller.failed'));

            return;
        }
        $this->ack($callbackId);
        $this->telegram->sendMessage($chatId, $this->texts->message('reseller.paid', ['reseller' => [
            'amount' => number_format((int) ($result->payment?->getPayableAmount() ?? 0)),
            'balance' => number_format($this->wallets->find($account->getUser())?->getBalance() ?? 0),
        ]]), ['inline_keyboard' => [[['text' => $this->texts->button('button.reseller.back'), 'callback_data' => 'reseller']]]]);
    }

    /**
     * @return array<string, array<string, string>>
     */
    private function quoteVariables(TelegramAccount $account, WholesaleQuote $quote): array
    {
        $balance = $this->wallets->find($account->getUser())?->getBalance() ?? 0;

        return ['reseller' => [
            'wholesale' => number_format($quote->wholesaleAmount),
            'retail' => number_format($quote->retailAmount),
            'balance' => number_format($balance),
            'after' => number_format($balance - $quote->wholesaleAmount),
        ]];
    }

    private function refusal(string $error): string
    {
        return match ($error) {
            'reseller_unavailable' => $this->texts->message('reseller.unavailable'),
            'sales_closed', 'plan_unavailable', 'invalid_options', 'price_unavailable', 'service_not_sold_by_you', 'daily_limit_reached', 'discount_not_for_resellers' => $this->texts->message('reseller.error.'.$error),
            'wallet_insufficient' => $this->texts->message('wallet.pay_insufficient'),
            'wallet_frozen' => $this->texts->message('wallet.frozen'),
            'wallet_disabled', 'wallet_payment_disabled' => $this->texts->message('wallet.disabled'),
            default => $this->texts->message('reseller.failed'),
        };
    }

    private static function gb(?int $bytes): string
    {
        return rtrim(rtrim(number_format(max(0, (int) $bytes) / 1073741824, 2, '.', ''), '0'), '.');
    }

    private function ack(?string $callbackId): void
    {
        if (null !== $callbackId && '' !== $callbackId) {
            $this->telegram->answerCallbackQuery($callbackId);
        }
    }

    private function popup(string $chatId, ?string $callbackId, string $text): void
    {
        if (null !== $callbackId && '' !== $callbackId) {
            $this->telegram->answerCallbackQuery($callbackId, $text, true);

            return;
        }
        $this->telegram->sendMessage($chatId, $text);
    }
}
