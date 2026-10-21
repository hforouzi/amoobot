<?php

declare(strict_types=1);

namespace App\Subscription\Application;

/**
 * One client app on the subscription page: its "Add subscription" deep link (null: none, copy the link instead) and
 * "Install app" link (an app download, else its store page).
 */
final class ClientAppView
{
    /**
     * @param list<string> $platforms
     */
    public function __construct(
        public readonly string $name,
        public readonly ?string $icon,
        public readonly array $platforms,
        public readonly ?string $importUrl,
        public readonly ?string $installUrl,
        public readonly bool $matchesPlatform,
    ) {
    }
}
