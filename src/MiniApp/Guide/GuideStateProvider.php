<?php

declare(strict_types=1);

namespace App\MiniApp\Guide;

use App\Bot\Application\StorePaymentMethodResolver;
use App\Payment\Domain\PaymentGatewayType;
use App\Provisioning\Application\TrafficAddonSettingsProvider;
use App\Reseller\Application\ResellerSettings;
use App\Shop\Application\Checkout\TrialCheckout;
use App\Shop\Application\SalesSettingsProvider;
use App\Wallet\Application\WalletSettings;

/**
 * The guide's GuideState from the same settings and services the shop itself uses, so the guide and the shop always
 * agree on what is offered (docs/design/help-guide.md, section 4.1).
 */
final class GuideStateProvider
{
    public function __construct(
        private readonly SalesSettingsProvider $salesSettings,
        private readonly TrafficAddonSettingsProvider $trafficAddonSettings,
        private readonly WalletSettings $walletSettings,
        private readonly ResellerSettings $resellerSettings,
        private readonly TrialCheckout $trialCheckout,
        private readonly StorePaymentMethodResolver $paymentMethods,
    ) {
    }

    public function current(): GuideState
    {
        $methods = $this->paymentMethods->getOfferableMethods();
        $types = array_map(static fn ($method): string => (string) $method->getGateway()->getType(), $methods);

        return new GuideState([
            'new_orders' => $this->salesSettings->newOrdersEnabled(),
            'renewals' => $this->salesSettings->renewalsEnabled(),
            'add_traffic' => $this->salesSettings->addTrafficEnabled() && $this->trafficAddonSettings->canPurchase(),
            'wallet' => $this->walletSettings->enabled(),
            'wallet_payment' => $this->walletSettings->paymentEnabled(),
            'claims' => $this->resellerSettings->claimsEnabled(),
            'trial' => [] !== $this->trialCheckout->activePlans(),
            'payment_methods' => [] !== $methods,
            'card_to_card' => in_array(PaymentGatewayType::MANUAL_CARD, $types, true),
            'crypto' => in_array(PaymentGatewayType::NOWPAYMENTS, $types, true),
            'online' => [] !== array_diff($types, [PaymentGatewayType::MANUAL_CARD, PaymentGatewayType::NOWPAYMENTS]),
        ], array_values(array_unique(array_map(static fn ($method): string => $method->getTitle(), $methods))));
    }
}
