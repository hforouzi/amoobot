<?php

declare(strict_types=1);

namespace App\Reseller\UI;

use App\Bot\Application\BotTextResolver;
use App\Bot\Application\MembershipGatePrompt;
use App\Bot\Application\TelegramChannelMembershipGate;
use App\Bot\Application\TelegramKeyboardFactory;
use App\Bot\Infrastructure\TelegramApiClient;
use App\Entity\Order;
use App\Entity\Reseller;
use App\Entity\TelegramAccount;
use App\Payment\Application\Settlement\SettlementOutcome;
use App\Payment\Application\Settlement\SettlementResult;
use App\Provisioning\Application\TrafficAddonSettingsProvider;
use App\Bot\Application\QrCodeSender;
use App\Reseller\Application\ClaimLinks;
use App\Reseller\Application\ResellerCheckout;
use App\Reseller\Application\ResellerSettings;
use App\Reseller\Domain\ClaimException;
use App\Reseller\Domain\ClaimStatus;
use App\Reseller\Domain\ResellerConfirmation;
use App\Reseller\Domain\ResellerException;
use App\Reseller\Domain\WholesaleQuote;
use App\Shop\Application\IncompleteOrderSettingsProvider;
use App\Wallet\Application\WalletService;
use App\Wallet\Application\WalletSettings;
use App\Wallet\Domain\WalletException;
use Psr\Log\LoggerInterface;

/**
 * The reseller menu in the bot (S1, minimal): balance, buying a new service at wholesale (fixed plans; a customizable
 * plan needs choices the Mini App will ask for), the services he sold, and renewing / adding traffic to them at
 * wholesale. Shown only to an active reseller while reseller.enabled is on; every callback checks that again.
 *
 * Callbacks: reseller, rs_new, rs_plan:{planId} (confirm), rs_buy:{planId}:{amount}:{nonce}, rs_sold:{page},
 * rs_svc:{serviceId}, rs_renew:{id} (confirm), rs_renew_ok:{id}:{amount}:{nonce}, rs_tr:{id} (choose GB),
 * rs_tr_gb:{id}:{gb} (confirm), rs_tr_ok:{id}:{gb}:{amount}:{nonce}; claim links (S2, with reseller.claims_enabled):
 * rs_link:{serviceId} (make one: shown once, with its QR), rs_links:{page} (his links), rs_rv:{claimId} (revoke).
 *
 * After a claim (decision D5) a sold service still shows here with its plan, status, expiry, traffic and "claimed on",
 * and can be renewed / given traffic at wholesale; never who claimed it, its links or anything else of the customer,
 * and no new claim link.
 *
 * Every price is quoted on the server (ResellerCheckout / ResellerPricing). The confirm step shows it and puts the
 * amount and a one-time nonce (ResellerConfirmations) into the pay button. The purchase checks both under the reseller
 * lock: a second delivery of the same tap (double tap while the first is running, Telegram redelivery) buys nothing and
 * says where the first one stands; a price that changed since, or an expired / unknown confirmation, shows a fresh
 * confirmation with the price now. After a failure the message says what really happened to the money (purchaseState).
 */
final class ResellerBotHandler
{
    public const SOLD_PER_PAGE = 8;
    private const PREFIXES = ['rs_plan:', 'rs_buy:', 'rs_cg:', 'rs_cd:', 'rs_buyc:', 'rs_sold:', 'rs_svc:', 'rs_renew:', 'rs_renew_ok:', 'rs_tr:', 'rs_tr_gb:', 'rs_tr_ok:', 'rs_link:', 'rs_links:', 'rs_rv:'];
    private const TRAFFIC_CHOICES = [10, 20, 50, 100];
    /** Suggested buttons for a customizable plan (only those inside the plan's range are shown, with its min and max). */
    private const PLAN_TRAFFIC_CHOICES = [10, 20, 30, 50, 100, 200];
    private const PLAN_DAY_CHOICES = [30, 60, 90, 180, 365];
    public const PROMPT_TRAFFIC = 'traffic';
    public const PROMPT_DAYS = 'days';
    /** The number of parts each customizable-plan callback must have (anything else is ignored). */
    private const EXACT_PARTS = ['rs_cg' => 3, 'rs_cd' => 4, 'rs_buyc' => 6, 'rs_link' => 2, 'rs_links' => 2, 'rs_rv' => 2];

    public function __construct(
        private readonly TelegramApiClient $telegram,
        private readonly BotTextResolver $texts,
        private readonly TelegramKeyboardFactory $keyboards,
        private readonly ResellerCheckout $checkout,
        private readonly WalletService $wallets,
        private readonly TrafficAddonSettingsProvider $trafficAddonSettings,
        private readonly MembershipGatePrompt $membershipGate,
        private readonly LoggerInterface $logger,
        private readonly IncompleteOrderSettingsProvider $incompleteOrders,
        private readonly WalletSettings $walletSettings,
        private readonly ResellerPromptStore $prompts,
        private readonly ClaimLinks $claimLinks,
        private readonly ResellerSettings $settings,
        private readonly QrCodeSender $qrCodeSender,
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
        // Any reseller button ends a pending "type a number" prompt (the ask steps start a new one).
        $this->prompts->clear($account->getTelegramId());
        $parts = explode(':', $data);
        if (isset(self::EXACT_PARTS[$parts[0]]) && count($parts) !== self::EXACT_PARTS[$parts[0]]) {
            $this->ack($callbackId);

            return;
        }
        $int = static fn (int $i): int => isset($parts[$i]) && ctype_digit($parts[$i]) && strlen($parts[$i]) <= 15 ? (int) $parts[$i] : 0;
        $first = $int(1);
        $second = $int(2);
        $third = $int(3);
        // The pay buttons: what was confirmed (amount, nonce) follows the ids.
        $confirmed = static fn (int $amountAt): ResellerConfirmation => new ResellerConfirmation($int($amountAt), (string) ($parts[$amountAt + 1] ?? ''));

        match ($parts[0]) {
            'reseller' => $this->showMenu($account, $chatId, $callbackId),
            'rs_new' => $this->showPlans($account, $chatId, $callbackId),
            'rs_plan' => $this->choosePlan($account, $chatId, $callbackId, $first),
            'rs_cg' => $this->trafficChosen($account, $chatId, $callbackId, $first, $second),
            'rs_cd' => $this->confirmCustom($account, $chatId, $callbackId, $first, $second, $third),
            'rs_buyc' => $this->buyCustom($account, $reseller, $chatId, $callbackId, $first, $second, $third, $confirmed(4)),
            'rs_buy' => $this->buyNew($account, $reseller, $chatId, $callbackId, $first, $confirmed(2)),
            'rs_sold' => $this->showSold($reseller, $chatId, $callbackId, max(1, $first)),
            'rs_svc' => $this->showService($account, $chatId, $callbackId, $first),
            'rs_renew' => $this->confirmRenew($account, $chatId, $callbackId, $first),
            'rs_renew_ok' => $this->settle($account, $reseller, $chatId, $callbackId, $confirmed(2)->nonce,
                fn (): SettlementResult => $this->checkout->renew($account->getUser(), $first, $confirmed(2)),
                fn (string $notice) => $this->confirmRenew($account, $chatId, $callbackId, $first, $notice)),
            'rs_tr' => $this->chooseTraffic($account, $chatId, $callbackId, $first),
            'rs_tr_gb' => $this->confirmTraffic($account, $chatId, $callbackId, $first, $second),
            'rs_tr_ok' => $this->settle($account, $reseller, $chatId, $callbackId, $confirmed(3)->nonce,
                fn (): SettlementResult => $this->checkout->addTraffic($account->getUser(), $first, $second, $confirmed(3)),
                fn (string $notice) => $this->confirmTraffic($account, $chatId, $callbackId, $first, $second, $notice)),
            'rs_link' => $this->makeClaimLink($account, $chatId, $callbackId, $first),
            'rs_links' => $this->showClaimLinks($reseller, $chatId, $callbackId, max(1, $first)),
            'rs_rv' => $this->revokeClaimLink($account, $reseller, $chatId, $callbackId, $first),
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
        if (!$this->walletSettings->paymentEnabled()) {
            $this->popup($chatId, $callbackId, $this->texts->message('wallet.disabled'));

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
        $rows = [
            [['text' => $this->texts->button('button.reseller.new_service'), 'callback_data' => 'rs_new']],
            [['text' => $this->texts->button('button.reseller.sold_services'), 'callback_data' => 'rs_sold:1']],
        ];
        if ($this->settings->claimsEnabled()) {
            $rows[] = [['text' => $this->texts->button('button.reseller.claim_links'), 'callback_data' => 'rs_links:1']];
        }
        $rows[] = [['text' => $this->texts->button('button.main.wallet'), 'callback_data' => 'wallet']];
        $rows[] = [['text' => $this->texts->button('button.common.back_to_main_menu'), 'callback_data' => 'main_menu']];
        $this->telegram->sendMessage($chatId, implode("\n\n", $lines), ['inline_keyboard' => $rows]);
    }

    /**
     * The plans with their wholesale price ("from" for a customizable plan). When none can be shown, the reason:
     * sales closed, nothing configured, nothing for sale, or nothing priceable for resellers.
     */
    private function showPlans(TelegramAccount $account, string $chatId, string $callbackId): void
    {
        try {
            $result = $this->checkout->newServiceOffers($account->getUser());
        } catch (ResellerException $e) {
            $this->popup($chatId, $callbackId, $this->refusal($e->error));

            return;
        }
        if (null !== $result['reason']) {
            $this->popup($chatId, $callbackId, $this->texts->message(match ($result['reason']) {
                ResellerCheckout::OFFERS_SALES_CLOSED => 'reseller.error.sales_closed',
                ResellerCheckout::OFFERS_NO_PLANS => 'reseller.plans_empty',
                ResellerCheckout::OFFERS_NOT_FOR_SALE => 'reseller.plans_not_for_sale',
                default => 'reseller.plans_no_wholesale',
            }));

            return;
        }
        $buttons = [];
        foreach ($result['offers'] as $offer) {
            $price = number_format($offer['quote']->wholesaleAmount);
            $buttons[] = [
                'text' => $offer['from']
                    ? $this->texts->message('reseller.plan_button_from', ['plan' => ['title' => $offer['plan']->getTitle()], 'reseller' => ['wholesale' => $price]])
                    : sprintf('%s — %s', $offer['plan']->getTitle(), $price),
                'callback_data' => 'rs_plan:'.$offer['plan']->getId(),
            ];
        }
        $this->ack($callbackId);
        $rows = $this->keyboards->inlineKeyboardRows($buttons, 1);
        $rows[] = [['text' => $this->texts->button('button.reseller.back'), 'callback_data' => 'reseller']];
        $this->telegram->sendMessage($chatId, $this->texts->message('reseller.plans_title'), ['inline_keyboard' => $rows]);
    }

    /** A fixed plan goes straight to its confirmation; a customizable one first asks its traffic and/or days. */
    private function choosePlan(TelegramAccount $account, string $chatId, string $callbackId, int $planId): void
    {
        try {
            $choices = $this->checkout->planChoices($account->getUser(), $planId);
        } catch (ResellerException $e) {
            $this->popup($chatId, $callbackId, $this->refusal($e->error));

            return;
        }
        match (true) {
            $choices['asksTraffic'] => $this->askTraffic($account, $chatId, $callbackId, $planId, $choices),
            $choices['asksDuration'] => $this->askDays($account, $chatId, $callbackId, $planId, 0, $choices),
            default => $this->confirmNew($account, $chatId, $callbackId, $planId),
        };
    }

    /**
     * @param array{minTraffic: int|null, maxTraffic: int|null} $choices
     */
    private function askTraffic(TelegramAccount $account, string $chatId, ?string $callbackId, int $planId, array $choices): void
    {
        $min = (int) $choices['minTraffic'];
        $max = (int) $choices['maxTraffic'];
        $this->ack($callbackId);
        $this->prompts->start($account->getTelegramId(), self::PROMPT_TRAFFIC, (string) $planId);
        $buttons = array_map(static fn (int $gb): array => ['text' => $gb.' GB', 'callback_data' => sprintf('rs_cg:%d:%d', $planId, $gb)], self::suggestions($min, $max, self::PLAN_TRAFFIC_CHOICES));
        $rows = $this->keyboards->inlineKeyboardRows($buttons, 3);
        $rows[] = [['text' => $this->texts->button('button.reseller.back'), 'callback_data' => 'rs_new']];
        $this->telegram->sendMessage($chatId, $this->texts->message('reseller.ask_traffic', ['range' => ['min' => $min, 'max' => $max]]), ['inline_keyboard' => $rows]);
    }

    private function trafficChosen(TelegramAccount $account, string $chatId, ?string $callbackId, int $planId, int $trafficGb): void
    {
        try {
            $choices = $this->checkout->planChoices($account->getUser(), $planId);
        } catch (ResellerException $e) {
            $this->popup($chatId, $callbackId, $this->refusal($e->error));

            return;
        }
        if (!$choices['asksTraffic'] || $trafficGb < (int) $choices['minTraffic'] || $trafficGb > (int) $choices['maxTraffic']) {
            $this->popup($chatId, $callbackId, $this->refusal('invalid_options'));

            return;
        }
        $choices['asksDuration']
            ? $this->askDays($account, $chatId, $callbackId, $planId, $trafficGb, $choices)
            : $this->confirmCustom($account, $chatId, $callbackId, $planId, $trafficGb, 0);
    }

    /**
     * @param array{minDays: int|null, maxDays: int|null} $choices
     */
    private function askDays(TelegramAccount $account, string $chatId, ?string $callbackId, int $planId, int $trafficGb, array $choices): void
    {
        $min = (int) $choices['minDays'];
        $max = (int) $choices['maxDays'];
        $this->ack($callbackId);
        $this->prompts->start($account->getTelegramId(), self::PROMPT_DAYS, $planId.':'.$trafficGb);
        $buttons = array_map(static fn (int $days): array => ['text' => (string) $days, 'callback_data' => sprintf('rs_cd:%d:%d:%d', $planId, $trafficGb, $days)], self::suggestions($min, $max, self::PLAN_DAY_CHOICES));
        $rows = $this->keyboards->inlineKeyboardRows($buttons, 3);
        $rows[] = [['text' => $this->texts->button('button.reseller.back'), 'callback_data' => 'rs_new']];
        $this->telegram->sendMessage($chatId, $this->texts->message('reseller.ask_days', ['range' => ['min' => $min, 'max' => $max]]), ['inline_keyboard' => $rows]);
    }

    /**
     * The quote and confirmation of a customizable plan with these choices (0 = not asked). The server checks the range
     * and prices it (ResellerCheckout / ResellerPricing); the pay button carries plan, GB, days, amount and nonce, the
     * nonce being signed over all of them.
     *
     * @param string|null $notice a message key shown above the confirmation
     */
    private function confirmCustom(TelegramAccount $account, string $chatId, ?string $callbackId, int $planId, int $trafficGb, int $days, ?string $notice = null): void
    {
        try {
            [, $plan, $quote, $confirmation] = $this->checkout->quoteNewService($account->getUser(), $planId, 0 === $trafficGb ? null : $trafficGb, 0 === $days ? null : $days);
        } catch (ResellerException $e) {
            $this->popup($chatId, $callbackId, $this->refusal($e->error));

            return;
        }
        $pay = sprintf('rs_buyc:%d:%d:%d:%d:%s', $planId, $trafficGb, $days, $confirmation->amount, $confirmation->nonce);
        if (strlen($pay) > 64) {
            // Telegram refuses longer callback data: never send a button that cannot work.
            $this->popup($chatId, $callbackId, $this->texts->message('reseller.failed'));

            return;
        }
        $this->ack($callbackId);
        $this->sendConfirmation($chatId, $notice, $this->texts->message('reseller.confirm_custom', [
            'plan' => ['title' => $plan->getTitle()],
            'order' => ['trafficGb' => 0 === $trafficGb ? '-' : $trafficGb, 'days' => 0 === $days ? '-' : $days],
        ] + $this->quoteVariables($account, $quote)), $pay, 'rs_new');
    }

    private function buyCustom(TelegramAccount $account, Reseller $reseller, string $chatId, string $callbackId, int $planId, int $trafficGb, int $days, ResellerConfirmation $confirmation): void
    {
        if (!$this->membershipGate->allowed($account, $chatId, $callbackId, TelegramChannelMembershipGate::CONTEXT_PURCHASE)) {
            return;
        }
        $this->settle($account, $reseller, $chatId, $callbackId, $confirmation->nonce,
            fn (): SettlementResult => $this->checkout->buyNewService($account->getUser(), $planId, $confirmation, 0 === $trafficGb ? null : $trafficGb, 0 === $days ? null : $days),
            fn (string $notice) => $this->confirmCustom($account, $chatId, $callbackId, $planId, $trafficGb, $days, $notice));
    }

    /**
     * A typed message while the reseller was asked for a number (traffic or days of a customizable plan). False when
     * there is no such prompt. The text is taken exactly as sent: digits only (ASCII, Persian or Arabic-Indic), no
     * spaces or other characters, nothing trimmed; anything else is refused and asked again.
     */
    public function answerPrompt(TelegramAccount $account, string $chatId, string $rawText): bool
    {
        $prompt = $this->prompts->get($account->getTelegramId());
        if (null === $prompt) {
            return false;
        }
        if ($prompt['expired'] || null === $this->checkout->resellerFor($account->getUser())) {
            $this->prompts->clear($account->getTelegramId());
            $this->telegram->sendMessage($chatId, $this->texts->message('reseller.prompt_expired'));

            return true;
        }
        $target = explode(':', $prompt['target']);
        $planId = ctype_digit($target[0]) ? (int) $target[0] : 0;
        try {
            $choices = $this->checkout->planChoices($account->getUser(), $planId);
        } catch (ResellerException $e) {
            $this->prompts->clear($account->getTelegramId());
            $this->telegram->sendMessage($chatId, $this->refusal($e->error));

            return true;
        }
        [$min, $max] = self::PROMPT_TRAFFIC === $prompt['kind'] ? [(int) $choices['minTraffic'], (int) $choices['maxTraffic']] : [(int) $choices['minDays'], (int) $choices['maxDays']];
        $value = self::parseNumber($rawText);
        if (null === $value || $value < $min || $value > $max) {
            // The prompt stays: the reseller types again.
            $this->telegram->sendMessage($chatId, $this->texts->message(null === $value ? 'reseller.prompt_not_a_number' : 'reseller.prompt_out_of_range', ['range' => ['min' => $min, 'max' => $max]]));

            return true;
        }
        $this->prompts->clear($account->getTelegramId());
        if (self::PROMPT_TRAFFIC === $prompt['kind']) {
            $this->trafficChosen($account, $chatId, null, $planId, $value);
        } else {
            $trafficGb = isset($target[1]) && ctype_digit($target[1]) ? (int) $target[1] : 0;
            $this->confirmCustom($account, $chatId, null, $planId, $trafficGb, $value);
        }

        return true;
    }

    /** Leaves a pending prompt (a command or a menu button was sent instead of a number). */
    public function dropPrompt(string $telegramUserId): void
    {
        $this->prompts->clear($telegramUserId);
    }

    /**
     * A whole number typed exactly: 1 to 6 digits (0-9, ۰-۹ or ٠-٩), nothing before or after (no spaces, no newline:
     * /D anchors the very end), else null.
     */
    public static function parseNumber(string $text): ?int
    {
        if (1 !== preg_match('/^[0-9\x{06F0}-\x{06F9}\x{0660}-\x{0669}]{1,6}$/uD', $text)) {
            return null;
        }
        $ascii = strtr($text, ['۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9', '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9']);

        return (int) $ascii;
    }

    /**
     * The buttons: the plan's min, the usual values inside the range, and its max.
     *
     * @param list<int> $usual
     *
     * @return list<int>
     */
    private static function suggestions(int $min, int $max, array $usual): array
    {
        $values = array_values(array_unique(array_filter([$min, ...$usual, $max], static fn (int $v): bool => $v >= $min && $v <= $max && $v > 0)));
        sort($values);

        return $values;
    }

    /**
     * @param string|null $notice a message key shown above the confirmation (the price changed, the confirmation expired)
     */
    private function confirmNew(TelegramAccount $account, string $chatId, string $callbackId, int $planId, ?string $notice = null): void
    {
        try {
            [, $plan, $quote, $confirmation] = $this->checkout->quoteNewService($account->getUser(), $planId);
        } catch (ResellerException $e) {
            $this->popup($chatId, $callbackId, $this->refusal($e->error));

            return;
        }
        $this->ack($callbackId);
        $this->sendConfirmation($chatId, $notice, $this->texts->message('reseller.confirm_new', ['plan' => ['title' => $plan->getTitle()]] + $this->quoteVariables($account, $quote)),
            sprintf('rs_buy:%d:%d:%s', $planId, $confirmation->amount, $confirmation->nonce), 'reseller');
    }

    private function buyNew(TelegramAccount $account, Reseller $reseller, string $chatId, string $callbackId, int $planId, ResellerConfirmation $confirmation): void
    {
        // The same required-channels rule as every other way to buy.
        if (!$this->membershipGate->allowed($account, $chatId, $callbackId, TelegramChannelMembershipGate::CONTEXT_PURCHASE)) {
            return;
        }
        $this->settle($account, $reseller, $chatId, $callbackId, $confirmation->nonce,
            fn (): SettlementResult => $this->checkout->buyNewService($account->getUser(), $planId, $confirmation),
            fn (string $notice) => $this->confirmNew($account, $chatId, $callbackId, $planId, $notice));
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
        $text = $this->texts->message('reseller.service', ['service' => [
            'id' => (int) $service->getId(),
            'username' => (string) $service->getUsername(),
            'status' => $this->texts->message('reseller.status.'.$service->getStatus()),
            'expiresAt' => $service->getExpiresAt()?->format('Y-m-d') ?? '∞',
            'traffic' => sprintf('%s / %s GB', self::gb($service->getTrafficUsedBytes()), null === $service->getTrafficLimitBytes() ? '∞' : self::gb($service->getTrafficLimitBytes())),
        ]]);
        $rows = [[['text' => $this->texts->button('button.reseller.renew'), 'callback_data' => 'rs_renew:'.$serviceId], ['text' => $this->texts->button('button.reseller.add_traffic'), 'callback_data' => 'rs_tr:'.$serviceId]]];
        $reseller = $this->checkout->resellerFor($account->getUser());
        if ($reseller instanceof Reseller && $this->settings->claimsEnabled()) {
            // Claimed: the date only, never by whom (D5). Still his: the link he may make, and the one still open.
            $claimedAt = $service->getUser()->getId() !== $account->getUser()->getId() ? $this->claimLinks->claimedAwayAt($reseller, $service) : null;
            $open = $this->claimLinks->openLinkOf($service);
            if (null !== $claimedAt) {
                $text .= "\n".$this->texts->message('reseller.claimed_on', ['claim' => ['claimedAt' => $claimedAt->format('Y-m-d H:i')]]);
            } elseif (null !== $open && $open->getReseller()?->getId() === $reseller->getId()) {
                $text .= "\n".$this->texts->message('reseller.claim_open', ['claim' => ['expiresAt' => $open->getExpiresAt()->format('Y-m-d H:i')]]);
            }
            if (null === $this->claimLinks->resellerProblem($account->getUser(), $service)) {
                $rows[] = [['text' => $this->texts->button(null === $open ? 'button.reseller.make_claim_link' : 'button.reseller.new_claim_link'), 'callback_data' => 'rs_link:'.$serviceId]];
            }
        }
        $rows[] = [['text' => $this->texts->button('button.reseller.back'), 'callback_data' => 'rs_sold:1']];
        $this->telegram->sendMessage($chatId, $text, ['inline_keyboard' => $rows]);
    }

    private function confirmRenew(TelegramAccount $account, string $chatId, string $callbackId, int $serviceId, ?string $notice = null): void
    {
        try {
            [, $service, $quote, $confirmation] = $this->checkout->quoteRenewal($account->getUser(), $serviceId);
        } catch (ResellerException $e) {
            $this->popup($chatId, $callbackId, $this->refusal($e->error));

            return;
        }
        $this->ack($callbackId);
        $this->sendConfirmation($chatId, $notice, $this->texts->message('reseller.confirm_renew', ['service' => ['id' => (int) $service->getId(), 'username' => (string) $service->getUsername()]] + $this->quoteVariables($account, $quote)),
            sprintf('rs_renew_ok:%d:%d:%s', $serviceId, $confirmation->amount, $confirmation->nonce), 'rs_svc:'.$serviceId);
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

    private function confirmTraffic(TelegramAccount $account, string $chatId, string $callbackId, int $serviceId, int $trafficGb, ?string $notice = null): void
    {
        try {
            [, $service, $quote, $confirmation] = $this->checkout->quoteAddTraffic($account->getUser(), $serviceId, $trafficGb);
        } catch (ResellerException $e) {
            $this->popup($chatId, $callbackId, $this->refusal($e->error));

            return;
        }
        $this->ack($callbackId);
        $this->sendConfirmation($chatId, $notice, $this->texts->message('reseller.confirm_traffic', ['service' => ['id' => (int) $service->getId(), 'username' => (string) $service->getUsername(), 'trafficGb' => $trafficGb]] + $this->quoteVariables($account, $quote)),
            sprintf('rs_tr_ok:%d:%d:%d:%s', $serviceId, $trafficGb, $confirmation->amount, $confirmation->nonce), 'rs_svc:'.$serviceId);
    }

    /**
     * Runs one purchase. Settlement already sent the service / delay / review message to the reseller; this adds what
     * the wallet paid and what is left. A changed price or an expired / unknown confirmation shows a fresh confirmation;
     * a second delivery of the same tap, and any failure, say where this purchase really stands (failureText()).
     *
     * @param \Closure(): SettlementResult $purchase
     * @param \Closure(string): void       $reconfirm shows a fresh confirmation, with a notice (a message key)
     */
    private function settle(TelegramAccount $account, Reseller $reseller, string $chatId, string $callbackId, string $nonce, \Closure $purchase, \Closure $reconfirm): void
    {
        try {
            $result = $purchase();
        } catch (ResellerException $e) {
            match ($e->error) {
                'price_changed' => $reconfirm('reseller.price_changed'),
                'confirmation_expired', 'confirmation_invalid' => $reconfirm('reseller.confirmation_expired'),
                'already_processed' => $this->answer($chatId, $callbackId, $this->failureText($reseller, $nonce, true)),
                default => $this->popup($chatId, $callbackId, $this->refusal($e->error)),
            };

            return;
        } catch (WalletException $e) {
            // Refused before anything was written (insufficient, frozen, ...), unless the debit was already committed
            // and the settlement step refused: then the state says so.
            $state = $this->checkout->purchaseState($reseller, $nonce)[0];
            $this->answer($chatId, $callbackId, ResellerCheckout::STATE_NONE === $state ? $this->refusal($e->error) : $this->failureText($reseller, $nonce));

            return;
        } catch (\Throwable $e) {
            $this->logger->error('Bot reseller purchase failed.', ['telegram_id' => $account->getTelegramId(), 'nonce' => $nonce, 'error' => $e->getMessage()]);
            $this->answer($chatId, $callbackId, $this->failureText($reseller, $nonce));

            return;
        }
        if (!in_array($result->outcome, [SettlementOutcome::CONFIRMED, SettlementOutcome::PROVISIONING_FAILED, SettlementOutcome::NEEDS_REVIEW], true)) {
            $this->answer($chatId, $callbackId, $this->failureText($reseller, $nonce));

            return;
        }
        $this->ack($callbackId);
        $this->telegram->sendMessage($chatId, $this->texts->message('reseller.paid', ['reseller' => [
            'amount' => number_format((int) ($result->payment?->getPayableAmount() ?? 0)),
            'balance' => number_format($this->wallets->find($account->getUser())?->getBalance() ?? 0),
        ]]), ['inline_keyboard' => [[['text' => $this->texts->button('button.reseller.back'), 'callback_data' => 'reseller']]]]);
    }

    /**
     * A new claim link for a service he sold and still owns, shown once: the t.me link (or, when the bot's username is
     * unknown, the /start text), the Mini App link when the app is on, and a QR of the link. The open link it replaces
     * (if any) stops working.
     */
    private function makeClaimLink(TelegramAccount $account, string $chatId, string $callbackId, int $serviceId): void
    {
        try {
            $link = $this->claimLinks->createForReseller($account->getUser(), $serviceId);
        } catch (ClaimException $e) {
            $this->popup($chatId, $callbackId, $this->texts->message(match ($e->error) {
                ClaimException::RATE_LIMITED => 'reseller.claim_error.rate_limited',
                ClaimException::BUSY => 'reseller.claim_error.busy',
                ClaimException::FEATURE_OFF, ClaimException::RESELLER_UNAVAILABLE => 'reseller.unavailable',
                default => 'reseller.claim_error.not_claimable',
            }));

            return;
        }
        $this->ack($callbackId);
        $variables = ['service' => ['id' => $serviceId], 'claim' => [
            'link' => $link->botLink ?? ('/start '.$link->payload()),
            'expiresAt' => $link->claim->getExpiresAt()->format('Y-m-d H:i'),
        ]];
        $lines = [$this->texts->message(null === $link->botLink ? 'reseller.claim_created_no_link' : 'reseller.claim_created', $variables)];
        if (null !== $link->appLink) {
            $lines[] = $this->texts->message('reseller.claim_created_app', ['claim' => ['appLink' => $link->appLink]]);
        }
        if ($link->replacedOpenLink) {
            $lines[] = $this->texts->message('reseller.claim_replaced');
        }
        $this->telegram->sendMessage($chatId, implode("\n\n", $lines), ['inline_keyboard' => [
            [['text' => $this->texts->button('button.reseller.claim_links'), 'callback_data' => 'rs_links:1']],
            [['text' => $this->texts->button('button.reseller.back'), 'callback_data' => 'rs_svc:'.$serviceId]],
        ]]);
        if (null !== $link->botLink) {
            $this->qrCodeSender->send($chatId, $link->botLink, $this->texts->message('reseller.claim_qr_caption', ['service' => ['id' => $serviceId]]), 'claim-link');
        }
    }

    /** His links, open first: service, status and date; a revoke button for each open one. Never who claimed. */
    private function showClaimLinks(Reseller $reseller, string $chatId, string $callbackId, int $page): void
    {
        if (!$this->settings->claimsEnabled()) {
            $this->popup($chatId, $callbackId, $this->texts->message('reseller.unavailable'));

            return;
        }
        $result = $this->claimLinks->linksOf($reseller, $page);
        if ([] === $result['items']) {
            $this->popup($chatId, $callbackId, $this->texts->message('reseller.claims_empty'));

            return;
        }
        $this->ack($callbackId);
        $lines = [$this->texts->message('reseller.claims_title', ['page' => $page])];
        $rows = [];
        foreach ($result['items'] as $claim) {
            $date = match ($claim->getStatus()) {
                ClaimStatus::OPEN => $claim->getExpiresAt(),
                ClaimStatus::CLAIMED => $claim->getClaimedAt(),
                ClaimStatus::REVOKED => $claim->getRevokedAt(),
                default => $claim->getExpiredAt() ?? $claim->getExpiresAt(),
            };
            $lines[] = $this->texts->message('reseller.claim_line.'.$claim->getStatus(), ['claim' => [
                'serviceId' => (int) $claim->getService()->getId(),
                'date' => $date?->format('Y-m-d H:i') ?? '-',
            ]]);
            if ($claim->isOpen()) {
                $rows[] = [['text' => $this->texts->message('reseller.claim_revoke_button', ['claim' => ['serviceId' => (int) $claim->getService()->getId()]]), 'callback_data' => 'rs_rv:'.$claim->getId()]];
            }
        }
        if ($result['hasNext']) {
            $rows[] = [['text' => $this->texts->button('button.reseller.next_page'), 'callback_data' => 'rs_links:'.($page + 1)]];
        }
        $rows[] = [['text' => $this->texts->button('button.reseller.back'), 'callback_data' => 'reseller']];
        $this->telegram->sendMessage($chatId, implode("\n", $lines), ['inline_keyboard' => $rows]);
    }

    private function revokeClaimLink(TelegramAccount $account, Reseller $reseller, string $chatId, string $callbackId, int $claimId): void
    {
        try {
            $revoked = $this->claimLinks->revokeByReseller($account->getUser(), $claimId);
        } catch (ClaimException) {
            // Its row stayed locked (someone is using the link right now): nothing changed.
            $this->popup($chatId, $callbackId, $this->texts->message('reseller.claim_error.busy'));

            return;
        }
        $this->telegram->answerCallbackQuery($callbackId, $this->texts->message($revoked ? 'reseller.claim_revoked' : 'reseller.claim_not_open'), true);
        $this->showClaimLinks($reseller, $chatId, '', 1);
    }

    /**
     * What the reseller is told after a failure or a second delivery of the same confirmation: read from the database
     * now (ResellerCheckout::purchaseState), so "nothing was taken" is said only when no order of this confirmation
     * exists. Otherwise: done, money held while it is processed (released automatically if it does not finish),
     * provisioning failed (money held for the admins' retry) or released back to the wallet.
     */
    public function failureText(Reseller $reseller, string $nonce, bool $repeated = false): string
    {
        [$state, $order] = $this->checkout->purchaseState($reseller, $nonce);

        return $this->stateText($state, $order, $repeated);
    }

    /**
     * A reseller order opened from the customer menus ("continue my incomplete order", order tracking): never the
     * card-to-card summary of a retail order; the same wording as after a failure (the money is held while it is
     * processed, done, ...). While its money is held, Cancel (the usual confirmation) closes it and gives the money
     * back once (PaymentSettlementService::cancelByCustomer).
     */
    public function showHeldOrder(Order $order, string $chatId, ?string $callbackId): void
    {
        $this->ack($callbackId);
        $state = $this->checkout->orderState($order);
        $rows = [];
        if (ResellerCheckout::STATE_HELD === $state) {
            $rows[] = [['text' => $this->texts->button('button.main.cancel_incomplete_order'), 'callback_data' => 'cancel_incomplete_order:'.$order->getId()]];
        }
        $rows[] = [['text' => $this->texts->button('button.reseller.back'), 'callback_data' => 'reseller']];
        $this->telegram->sendMessage($chatId, $this->stateText($state, $order), ['inline_keyboard' => $rows]);
    }

    private function stateText(string $state, ?Order $order, bool $repeated = false): string
    {
        $variables = ['order' => [
            'trackingCode' => (string) ($order?->getTrackingCode() ?? '-'),
            'amount' => number_format((int) ($order?->getAmount() ?? 0)),
            'hours' => $this->incompleteOrders->expireHours(),
        ]];

        return match ($state) {
            ResellerCheckout::STATE_NONE => $this->texts->message('reseller.failed'),
            ResellerCheckout::STATE_DONE => $this->texts->message($repeated ? 'reseller.already_done' : 'reseller.state_done', $variables),
            ResellerCheckout::STATE_HELD => $this->texts->message('reseller.state_held', $variables),
            ResellerCheckout::STATE_FAILED => $this->texts->message('reseller.state_provisioning_failed', $variables),
            default => $this->texts->message('reseller.state_released', $variables),
        };
    }

    /**
     * @param string|null $notice a message key shown above the confirmation
     */
    private function sendConfirmation(string $chatId, ?string $notice, string $text, string $payData, string $cancelData): void
    {
        $this->telegram->sendMessage($chatId, null === $notice ? $text : $this->texts->message($notice)."\n\n".$text, ['inline_keyboard' => [
            [['text' => $this->texts->button('button.reseller.confirm'), 'callback_data' => $payData]],
            [['text' => $this->texts->button('button.reseller.cancel'), 'callback_data' => $cancelData]],
        ]]);
    }

    /** A message (not a popup: it can be long), the callback acknowledged. */
    private function answer(string $chatId, ?string $callbackId, string $text): void
    {
        $this->ack($callbackId);
        $this->telegram->sendMessage($chatId, $text, ['inline_keyboard' => [[['text' => $this->texts->button('button.reseller.back'), 'callback_data' => 'reseller']]]]);
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
            // Not the customer's "choose another method": a reseller pays from his wallet only.
            'wallet_insufficient' => $this->texts->message('reseller.error.wallet_insufficient'),
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
