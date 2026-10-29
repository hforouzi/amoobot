<?php

declare(strict_types=1);

namespace App\MiniApp\Reseller;

use App\Entity\Order;
use App\Entity\Reseller;
use App\MiniApp\Checkout\CheckoutFailure;
use App\MiniApp\Checkout\CheckoutIdempotency;
use App\MiniApp\Security\MiniAppUser;
use App\Payment\Application\Settlement\SettlementOutcome;
use App\Payment\Application\Settlement\SettlementResult;
use App\Reseller\Application\ResellerCheckout;
use App\Reseller\Application\ResellerConfirmations;
use App\Reseller\Domain\ResellerConfirmation;
use App\Reseller\Domain\ResellerException;
use App\Reseller\Domain\WholesaleQuote;
use App\Shop\Application\OrderServiceAccess;
use App\Wallet\Application\WalletService;
use App\Wallet\Domain\WalletException;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * A reseller's purchase from the Mini App (new service, renewal, extra traffic), through exactly the bot's path:
 * ResellerCheckout quotes on the server and issues the one-time signed confirmation (ResellerConfirmations), and buys
 * for that confirmation from the wallet. The client sends ids and choices, never an amount.
 *
 * The confirmation step is the Mini App's usual one (as "pay from wallet"): a call without a confirmation (or with an
 * expired / invalid one, or after the price changed) changes nothing and is answered 428 with
 * {confirm: {token, message}}: the token is "<amount>.<nonce>" from ResellerConfirmations, the message says what is
 * paid and what the balance will be. The app shows it in Telegram's confirm dialog and repeats the call with the token
 * (X-Admin-Confirm) and the same Idempotency-Key only on OK. The amount in the token is only compared with the price
 * now (price_changed otherwise: a new 428 with the new price), never charged.
 *
 * A double submit is one order, twice over: the Idempotency-Key runs the call once (CheckoutIdempotency::claimOnce:
 * a repeat gets the first answer, a parallel duplicate idempotency_in_progress), and the confirmation's nonce is stored
 * on the order under the reseller lock (a second delivery with another key finds the first order: already_processed,
 * answered with where that order stands). Rate limited per Telegram user (miniapp_reseller).
 */
class ResellerPurchases
{
    public const KIND_NEW = ResellerConfirmations::KIND_NEW;
    public const KIND_RENEWAL = ResellerConfirmations::KIND_RENEWAL;
    public const KIND_TRAFFIC = ResellerConfirmations::KIND_TRAFFIC;

    // /D: the very end (no trailing newline). The nonce part is ResellerConfirmations' own format.
    private const TOKEN_PATTERN = '/^([1-9][0-9]{0,11})\.([0-9a-z]{7}[0-9a-f]{6}[A-Za-z0-9_-]{10})$/D';

    public function __construct(
        private readonly ResellerCheckout $checkout,
        private readonly MiniAppResellerAccess $access,
        private readonly CheckoutIdempotency $idempotency,
        private readonly WalletService $wallets,
        private readonly OrderServiceAccess $orderServices,
        private readonly UrlGeneratorInterface $urls,
        private readonly TranslatorInterface $translator,
        private readonly LoggerInterface $logger,
        #[Autowire(service: 'limiter.miniapp_reseller')]
        private readonly RateLimiterFactory $limiter,
    ) {
    }

    /**
     * One purchase call. $target is the plan (new) or the service (renewal, traffic); $trafficGb / $days are the
     * choices (0 = not asked) exactly as the quote was asked for them.
     *
     * @return array{int, array<string, mixed>} HTTP status and JSON body
     */
    public function purchase(MiniAppUser $user, string $kind, int $target, int $trafficGb, int $days, ?string $idempotencyKey, ?string $confirmToken, string $locale): array
    {
        $reseller = $this->access->require($user);
        $confirmation = self::parseToken($confirmToken);
        if (null === $confirmation) {
            return $this->confirmStep($user, $kind, $target, $trafficGb, $days, null, $locale);
        }
        if (!$this->limiter->create($user->telegramUserId())->consume()->isAccepted()) {
            return $this->error('rate_limited', Response::HTTP_TOO_MANY_REQUESTS, $locale);
        }

        try {
            $answer = $this->idempotency->claimOnce($user->customer(), $idempotencyKey, sprintf('reseller_%s:%d:%d:%d', $kind, $target, $trafficGb, $days), function () use ($user, $reseller, $kind, $target, $trafficGb, $days, $confirmation, $locale): array {
                $result = match ($kind) {
                    self::KIND_NEW => $this->checkout->buyNewService($user->customer(), $target, $confirmation, 0 === $trafficGb ? null : $trafficGb, 0 === $days ? null : $days),
                    self::KIND_RENEWAL => $this->checkout->renew($user->customer(), $target, $confirmation),
                    default => $this->checkout->addTraffic($user->customer(), $target, $trafficGb, $confirmation),
                };

                return $this->settled($user, $reseller, $result, $confirmation->nonce, $locale);
            });
        } catch (CheckoutFailure $failure) {
            return $this->error($failure->error, $failure->status, $locale);
        } catch (ResellerException $e) {
            return match ($e->error) {
                'price_changed' => $this->confirmStep($user, $kind, $target, $trafficGb, $days, 'reseller.confirm.price_changed', $locale),
                'confirmation_expired', 'confirmation_invalid' => $this->confirmStep($user, $kind, $target, $trafficGb, $days, 'reseller.confirm.expired', $locale),
                'already_processed' => [Response::HTTP_OK, $this->stateAnswer($reseller, $confirmation->nonce, $locale, true)],
                default => $this->refused($e->error, $locale),
            };
        } catch (WalletException $e) {
            // Refused before anything was written (insufficient, frozen, ...), unless the debit was already committed
            // and settlement refused: then the order's state says so.
            [$state] = $this->checkout->purchaseState($reseller, $confirmation->nonce);

            return ResellerCheckout::STATE_NONE === $state ? $this->refused($e->error, $locale) : [Response::HTTP_OK, $this->stateAnswer($reseller, $confirmation->nonce, $locale)];
        } catch (\Throwable $e) {
            $this->logger->error('Mini App reseller purchase failed.', ['telegram_id' => $user->telegramUserId(), 'nonce' => $confirmation->nonce, 'error' => $e->getMessage()]);

            return [Response::HTTP_OK, $this->stateAnswer($reseller, $confirmation->nonce, $locale)];
        }

        return [Response::HTTP_OK, $answer];
    }

    /**
     * The price of this purchase now, for the form's price box. Nothing is written; the confirmation it comes with is
     * not handed out (the purchase call asks for its own).
     *
     * @throws ResellerException
     */
    public function quote(MiniAppUser $user, string $kind, int $target, int $trafficGb, int $days): WholesaleQuote
    {
        $this->access->require($user);

        return $this->quoteWithConfirmation($user, $kind, $target, $trafficGb, $days)[0];
    }

    /**
     * The wording of a refusal (ResellerException / WalletException / CheckoutFailure code), the same cases as the bot.
     */
    public function refusalMessage(string $error, string $locale): string
    {
        $key = match ($error) {
            'sales_closed', 'plan_unavailable', 'invalid_options', 'price_unavailable', 'service_not_sold_by_you', 'daily_limit_reached',
            'discount_not_for_resellers', 'wallet_insufficient', 'wallet_frozen', 'reseller_unavailable' => 'reseller.error.'.$error,
            'wallet_disabled', 'wallet_payment_disabled' => 'reseller.error.wallet_disabled',
            'rate_limited', 'idempotency_conflict', 'idempotency_key_required' => 'checkout.message.'.$error,
            'idempotency_in_progress' => 'reseller.error.in_progress',
            default => 'reseller.error.failed',
        };
        $text = $this->translator->trans($key, [], 'miniapp', $locale);

        return $text === $key ? $this->translator->trans('state.error', [], 'miniapp', $locale) : $text;
    }

    /**
     * @return array{WholesaleQuote, ResellerConfirmation}
     *
     * @throws ResellerException
     */
    private function quoteWithConfirmation(MiniAppUser $user, string $kind, int $target, int $trafficGb, int $days): array
    {
        $quoted = match ($kind) {
            self::KIND_NEW => $this->checkout->quoteNewService($user->customer(), $target, 0 === $trafficGb ? null : $trafficGb, 0 === $days ? null : $days),
            self::KIND_RENEWAL => $this->checkout->quoteRenewal($user->customer(), $target),
            default => $this->checkout->quoteAddTraffic($user->customer(), $target, $trafficGb),
        };

        return [$quoted[2], $quoted[3]];
    }

    /**
     * 428 with a fresh confirmation of the price now ("Pay X from your wallet? Balance Y -> Z"), with a notice first
     * when the price changed or the confirmation ran out. Nothing is written.
     *
     * @return array{int, array<string, mixed>}
     */
    private function confirmStep(MiniAppUser $user, string $kind, int $target, int $trafficGb, int $days, ?string $notice, string $locale): array
    {
        try {
            [$quote, $confirmation] = $this->quoteWithConfirmation($user, $kind, $target, $trafficGb, $days);
        } catch (ResellerException $e) {
            return $this->refused($e->error, $locale);
        }
        $balance = $this->wallets->find($user->customer())?->getBalance() ?? 0;
        $message = $this->translator->trans('reseller.confirm.message', [
            '%amount%' => number_format($quote->wholesaleAmount),
            '%retail%' => number_format($quote->retailAmount),
            '%balance%' => number_format($balance),
            '%after%' => number_format($balance - $quote->wholesaleAmount),
        ], 'miniapp', $locale);
        if (null !== $notice) {
            $message = $this->translator->trans($notice, [], 'miniapp', $locale)."\n\n".$message;
        }

        return [Response::HTTP_PRECONDITION_REQUIRED, [
            'error' => 'confirm_required',
            'confirm' => ['token' => $confirmation->amount.'.'.$confirmation->nonce, 'message' => $message],
        ]];
    }

    /**
     * The answer after the wallet paid: the sold service's screen when it is ready, else the order with where it stands.
     *
     * @return array<string, mixed>
     */
    private function settled(MiniAppUser $user, Reseller $reseller, SettlementResult $result, string $nonce, string $locale): array
    {
        $order = $result->payment?->getOrder();
        if (SettlementOutcome::CONFIRMED !== $result->outcome || !$order instanceof Order) {
            return $this->stateAnswer($reseller, $nonce, $locale);
        }
        $service = $result->vpnService ?? $this->orderServices->serviceOf($order);

        return [
            'message' => $this->translator->trans('reseller.paid', [
                '%amount%' => number_format($order->getAmount()),
                '%balance%' => number_format($this->wallets->find($user->customer())?->getBalance() ?? 0),
            ], 'miniapp', $locale),
            'redirect' => null !== $service?->getId()
                ? $this->urls->generate('miniapp_reseller_service', ['id' => $service->getId()])
                : $this->urls->generate('miniapp_order', ['id' => $order->getId()]),
        ];
    }

    /**
     * Where the purchase of this confirmation stands, read now (ResellerCheckout::purchaseState): "nothing was taken"
     * only when no order of it exists; otherwise its order screen with done / held / provisioning failed / released.
     *
     * @return array<string, mixed>
     */
    private function stateAnswer(Reseller $reseller, string $nonce, string $locale, bool $repeated = false): array
    {
        [$state, $order] = $this->checkout->purchaseState($reseller, $nonce);
        if (!$order instanceof Order) {
            return ['error' => 'purchase_failed', 'message' => $this->translator->trans('reseller.error.failed', [], 'miniapp', $locale)];
        }
        $key = match ($state) {
            ResellerCheckout::STATE_DONE => $repeated ? 'reseller.state.already_done' : 'reseller.state.done',
            ResellerCheckout::STATE_HELD => 'reseller.state.held',
            ResellerCheckout::STATE_FAILED => 'reseller.state.provisioning_failed',
            default => 'reseller.state.released',
        };

        return [
            'message' => $this->translator->trans($key, ['%code%' => (string) ($order->getTrackingCode() ?? $order->getId()), '%amount%' => number_format($order->getAmount())], 'miniapp', $locale),
            'redirect' => $this->urls->generate('miniapp_order', ['id' => $order->getId()]),
            'state' => $state,
        ];
    }

    /**
     * @return array{int, array<string, mixed>}
     */
    private function refused(string $error, string $locale): array
    {
        $status = match ($error) {
            'invalid_options', 'discount_not_for_resellers' => Response::HTTP_UNPROCESSABLE_ENTITY,
            'reseller_unavailable', 'service_not_sold_by_you', 'plan_unavailable' => Response::HTTP_NOT_FOUND,
            default => Response::HTTP_CONFLICT,
        };

        return $this->error($error, $status, $locale);
    }

    /**
     * @return array{int, array<string, mixed>}
     */
    private function error(string $error, int $status, string $locale): array
    {
        return [$status, ['error' => $error, 'message' => $this->refusalMessage($error, $locale)]];
    }

    /** "<amount>.<nonce>" exactly, else null (then the confirmation step runs; nothing is bought). */
    private static function parseToken(?string $token): ?ResellerConfirmation
    {
        if (null === $token || 1 !== preg_match(self::TOKEN_PATTERN, $token, $m)) {
            return null;
        }

        return new ResellerConfirmation((int) $m[1], $m[2]);
    }
}
