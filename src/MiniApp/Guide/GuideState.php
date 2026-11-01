<?php

declare(strict_types=1);

namespace App\MiniApp\Guide;

/**
 * What the shop offers right now, as the guide needs it (GuideStateProvider): which features are on, and the labels of
 * the payment methods customers can use. The same for every customer: nothing of one customer is in it.
 */
final readonly class GuideState
{
    /** The feature flags a guide text may test with "::: if <flag>". */
    public const FLAGS = ['new_orders', 'renewals', 'add_traffic', 'wallet', 'wallet_payment', 'claims', 'trial', 'payment_methods', 'card_to_card', 'online', 'crypto'];

    /**
     * @param array<string, bool> $flags          by GuideState::FLAGS; a missing flag is off
     * @param list<string>        $paymentMethods the titles of the store payment methods customers can use (labels only)
     */
    public function __construct(
        private array $flags,
        public array $paymentMethods = [],
    ) {
    }

    /** Whether a feature is on; an unknown flag is off (fail closed). */
    public function flag(string $name): bool
    {
        return in_array($name, self::FLAGS, true) && true === ($this->flags[$name] ?? false);
    }
}
