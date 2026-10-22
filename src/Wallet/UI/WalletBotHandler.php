<?php

declare(strict_types=1);

namespace App\Wallet\UI;

use App\Bot\Application\BotTextResolver;
use App\Bot\Application\TelegramKeyboardFactory;
use App\Bot\Infrastructure\TelegramApiClient;
use App\Entity\Order;
use App\Entity\TelegramAccount;
use App\Entity\Wallet;
use App\Entity\WalletTransaction;
use App\Payment\Application\Settlement\SettlementOutcome;
use App\Shop\Application\Checkout\PaymentStarter;
use App\Wallet\Application\WalletPayment;
use App\Wallet\Application\WalletQueries;
use App\Wallet\Application\WalletService;
use App\Wallet\Application\WalletSettings;
use App\Wallet\Application\WalletTopUp;
use App\Wallet\Domain\WalletException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * The customer's wallet in the bot: "My wallet" (balance, credit limit, frozen notice, last transactions), the
 * ledger page by page, a top-up (quick amounts; the order is then paid like any order with the store payment methods,
 * see TelegramUpdateHandler::handleSelectStorePaymentMethod) and "pay from wallet" for an order waiting for payment.
 *
 * Callbacks: wallet, wallet_tx:{page}, wallet_topup, wallet_topup:{amount}, wallet_pay:{orderId}. Everything is
 * re-checked here (owner, settings, amounts); a double tap is harmless: a top-up creates a new unpaid order at most,
 * a wallet payment is serialized by the order lock and the ledger's idempotency key.
 */
final class WalletBotHandler
{
    public const TRANSACTIONS_PER_PAGE = 10;
    private const OVERVIEW_TRANSACTIONS = 5;
    private const PREFIXES = ['wallet_tx:', 'wallet_topup:', 'wallet_pay:'];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly TelegramApiClient $telegram,
        private readonly BotTextResolver $texts,
        private readonly TelegramKeyboardFactory $keyboards,
        private readonly WalletSettings $settings,
        private readonly WalletService $wallets,
        private readonly WalletQueries $queries,
        private readonly WalletTopUp $topUp,
        private readonly WalletPayment $walletPayment,
        private readonly PaymentStarter $paymentStarter,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function supports(string $data): bool
    {
        if (in_array($data, ['wallet', 'wallet_topup'], true)) {
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
        $parts = explode(':', $data, 2);
        $hasArgument = isset($parts[1]);
        $argument = $hasArgument && ctype_digit($parts[1]) ? (int) $parts[1] : 0;

        match (true) {
            'wallet' === $parts[0] => $this->showWallet($account, $chatId, $callbackId),
            'wallet_tx' === $parts[0] => $this->showTransactions($account, $chatId, $callbackId, max(1, $argument)),
            'wallet_topup' === $parts[0] && !$hasArgument => $this->askAmount($account, $chatId, $callbackId),
            'wallet_topup' === $parts[0] => $this->startTopUp($account, $chatId, $callbackId, $argument),
            'wallet_pay' === $parts[0] => $this->pay($account, $chatId, $callbackId, $argument),
            default => $this->telegram->answerCallbackQuery($callbackId),
        };
    }

    /** "My wallet": the main menu button (no callback) or the wallet callback. */
    public function showWallet(TelegramAccount $account, string $chatId, ?string $callbackId): void
    {
        if (!$this->settings->enabled()) {
            $this->popup($chatId, $callbackId, $this->texts->message('wallet.disabled'));

            return;
        }
        $this->ack($callbackId);
        $wallet = $this->wallets->find($account->getUser());
        $lines = [$this->texts->message('wallet.overview', ['wallet' => ['balance' => number_format($wallet?->getBalance() ?? 0)]])];
        if (null !== $wallet && $wallet->getCreditLimit() > 0) {
            $lines[] = $this->texts->message('wallet.overview_credit', ['wallet' => ['creditLimit' => number_format($wallet->getCreditLimit()), 'available' => number_format($wallet->getAvailable())]]);
        }
        if (true === $wallet?->isFrozen()) {
            $lines[] = $this->texts->message('wallet.overview_frozen');
        }
        if (null !== $wallet) {
            $recent = $this->queries->transactions($wallet, 1, self::OVERVIEW_TRANSACTIONS)['items'];
            if ([] !== $recent) {
                $lines[] = $this->texts->message('wallet.transactions_title', ['page' => 1]);
                foreach ($recent as $transaction) {
                    $lines[] = $this->line($transaction);
                }
            }
        }

        $rows = [];
        if (!$wallet?->isFrozen()) {
            $rows[] = [['text' => $this->texts->button('button.wallet.topup'), 'callback_data' => 'wallet_topup']];
        }
        $rows[] = [['text' => $this->texts->button('button.wallet.transactions'), 'callback_data' => 'wallet_tx:1']];
        $rows[] = [['text' => $this->texts->button('button.common.back_to_main_menu'), 'callback_data' => 'main_menu']];
        $this->telegram->sendMessage($chatId, implode("\n\n", $lines), ['inline_keyboard' => $rows]);
    }

    private function showTransactions(TelegramAccount $account, string $chatId, string $callbackId, int $page): void
    {
        $wallet = $this->settings->enabled() ? $this->wallets->find($account->getUser()) : null;
        if (!$wallet instanceof Wallet) {
            $this->popup($chatId, $callbackId, $this->texts->message($this->settings->enabled() ? 'wallet.transactions_empty' : 'wallet.disabled'));

            return;
        }
        $this->ack($callbackId);
        $result = $this->queries->transactions($wallet, $page, self::TRANSACTIONS_PER_PAGE);
        $lines = [$this->texts->message('wallet.transactions_title', ['page' => $page])];
        foreach ($result['items'] as $transaction) {
            $lines[] = $this->line($transaction);
        }
        if ([] === $result['items']) {
            $lines[] = $this->texts->message('wallet.transactions_empty');
        }

        $rows = [];
        if ($result['hasNext']) {
            $rows[] = [['text' => $this->texts->button('button.wallet.next_page'), 'callback_data' => 'wallet_tx:'.($page + 1)]];
        }
        $rows[] = [['text' => $this->texts->button('button.wallet.back'), 'callback_data' => 'wallet']];
        $this->telegram->sendMessage($chatId, implode("\n", $lines), ['inline_keyboard' => $rows]);
    }

    private function askAmount(TelegramAccount $account, string $chatId, string $callbackId): void
    {
        if (null !== ($refusal = $this->topUpRefusal($account))) {
            $this->popup($chatId, $callbackId, $refusal);

            return;
        }
        $this->ack($callbackId);
        $buttons = array_map(static fn (int $amount): array => ['text' => number_format($amount), 'callback_data' => 'wallet_topup:'.$amount], $this->settings->quickAmounts());
        $rows = $this->keyboards->inlineKeyboardRows($buttons, 2);
        $rows[] = [['text' => $this->texts->button('button.wallet.back'), 'callback_data' => 'wallet']];
        $this->telegram->sendMessage($chatId, $this->texts->message('wallet.topup_choose_amount', ['wallet' => [
            'min' => number_format($this->settings->topUpMin()),
            'max' => number_format($this->settings->topUpMax()),
        ]]), ['inline_keyboard' => $rows]);
    }

    private function startTopUp(TelegramAccount $account, string $chatId, string $callbackId, int $amount): void
    {
        try {
            $order = $this->topUp->createOrder($account->getUser(), $amount);
        } catch (WalletException $e) {
            $this->popup($chatId, $callbackId, $this->refusalText($e->error));

            return;
        }

        $methods = $this->paymentStarter->methodsFor($order);
        if ([] === $methods) {
            $this->popup($chatId, $callbackId, $this->texts->message('payment.no_methods'));

            return;
        }
        $this->ack($callbackId);
        $this->telegram->sendMessage($chatId, $this->texts->message('wallet.topup_order', [
            'wallet' => ['amount' => number_format($order->getAmount())],
            'order' => ['trackingCode' => (string) ($order->getTrackingCode() ?? '-')],
        ]), $this->keyboards->paymentGatewaySelectionMenu((int) $order->getId(), $methods, 'wallet', 'order_cancel:'.$order->getId()));
    }

    private function pay(TelegramAccount $account, string $chatId, string $callbackId, int $orderId): void
    {
        $order = $orderId > 0 ? $this->entityManager->getRepository(Order::class)->find($orderId) : null;
        if (!$order instanceof Order || $order->getUser()->getId() !== $account->getUser()->getId()) {
            $this->popup($chatId, $callbackId, $this->texts->message('order.not_found'));

            return;
        }

        try {
            $result = $this->walletPayment->pay($order);
        } catch (WalletException $e) {
            $this->popup($chatId, $callbackId, $this->refusalText($e->error));

            return;
        } catch (\Throwable $e) {
            $this->logger->error('Bot wallet payment failed.', ['order_id' => $orderId, 'error' => $e->getMessage()]);
            $this->popup($chatId, $callbackId, $this->texts->message('wallet.pay_failed'));

            return;
        }

        // Settlement already sent the service / delay / review message; add what the wallet paid and what is left.
        if (in_array($result->outcome, [SettlementOutcome::CONFIRMED, SettlementOutcome::PROVISIONING_FAILED, SettlementOutcome::NEEDS_REVIEW], true)) {
            $this->ack($callbackId);
            $this->telegram->sendMessage($chatId, $this->texts->message('wallet.paid', ['wallet' => [
                'amount' => number_format((int) ($result->payment?->getPayableAmount() ?? $order->getAmount())),
                'balance' => number_format($this->wallets->find($account->getUser())?->getBalance() ?? 0),
            ]]));

            return;
        }
        $this->popup($chatId, $callbackId, $this->texts->message(SettlementOutcome::ALREADY_PROCESSED === $result->outcome ? 'order.not_found' : 'wallet.pay_failed'));
    }

    private function topUpRefusal(TelegramAccount $account): ?string
    {
        if (!$this->settings->enabled()) {
            return $this->texts->message('wallet.disabled');
        }

        return true === $this->wallets->find($account->getUser())?->isFrozen() ? $this->texts->message('wallet.frozen') : null;
    }

    private function refusalText(string $error): string
    {
        return match ($error) {
            'wallet_insufficient' => $this->texts->message('wallet.pay_insufficient'),
            'wallet_frozen' => $this->texts->message('wallet.frozen'),
            'wallet_disabled', 'wallet_payment_disabled' => $this->texts->message('wallet.disabled'),
            'topup_out_of_range' => $this->texts->message('wallet.topup_out_of_range', ['wallet' => ['min' => number_format($this->settings->topUpMin()), 'max' => number_format($this->settings->topUpMax())]]),
            'order_not_payable', 'plan_unavailable', 'payment_in_progress' => $this->texts->message('order.not_found'),
            default => $this->texts->message('wallet.pay_failed'),
        };
    }

    private function line(WalletTransaction $transaction): string
    {
        return $this->texts->message('wallet.transaction_line', ['tx' => [
            'date' => $transaction->getCreatedAt()->format('Y-m-d H:i'),
            'type' => $this->texts->message('wallet.tx_type.'.$transaction->getType()),
            'amount' => ($transaction->getAmount() > 0 ? '+' : '−').number_format(abs($transaction->getAmount())),
            'balance' => number_format($transaction->getBalanceAfter()),
        ]]);
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
