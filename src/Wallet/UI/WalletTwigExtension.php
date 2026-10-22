<?php

declare(strict_types=1);

namespace App\Wallet\UI;

use App\Wallet\Application\WalletSettings;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/** wallet_enabled(): whether customer pages show the wallet (links, cards). */
final class WalletTwigExtension extends AbstractExtension
{
    public function __construct(private readonly WalletSettings $settings)
    {
    }

    public function getFunctions(): array
    {
        return [new TwigFunction('wallet_enabled', $this->settings->enabled(...))];
    }
}
