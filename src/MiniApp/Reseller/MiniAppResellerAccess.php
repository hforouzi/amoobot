<?php

declare(strict_types=1);

namespace App\MiniApp\Reseller;

use App\Entity\Reseller;
use App\MiniApp\Security\MiniAppUser;
use App\Reseller\Application\ResellerCheckout;
use App\Reseller\Application\ResellerSettings;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Who is a reseller in the Mini App: the signed-in customer's reseller profile while reseller.enabled is on and the
 * profile is active (ResellerCheckout::resellerFor, the bot's rule), else nobody. The navigation, the section guard
 * and every reseller controller ask it; the answer is remembered for the request (the navigation and the guard ask
 * more than once) and forgotten between requests (ResetInterface). The application services check again under their
 * own locks (a disable in between is seen there).
 */
class MiniAppResellerAccess implements ResetInterface
{
    /** @var array<int, Reseller|null> by user id */
    private array $known = [];

    public function __construct(
        private readonly ResellerCheckout $checkout,
        private readonly ResellerSettings $settings,
    ) {
    }

    public function resellerOf(MiniAppUser $user): ?Reseller
    {
        if (!$this->settings->enabled()) {
            // Off: not even a query.
            return null;
        }
        $userId = (int) $user->customer()->getId();
        if (!array_key_exists($userId, $this->known)) {
            $this->known[$userId] = $this->checkout->resellerFor($user->customer());
        }

        return $this->known[$userId];
    }

    /**
     * @throws NotFoundHttpException not a reseller now (the same answer as a route that does not exist)
     */
    public function require(MiniAppUser $user): Reseller
    {
        return $this->resellerOf($user) ?? throw new NotFoundHttpException();
    }

    /**
     * Claim links in the reseller screens: claims on too (reseller.claims_enabled).
     *
     * @throws NotFoundHttpException
     */
    public function requireClaims(MiniAppUser $user): Reseller
    {
        $reseller = $this->require($user);
        if (!$this->settings->claimsEnabled()) {
            throw new NotFoundHttpException();
        }

        return $reseller;
    }

    public function claimsEnabled(): bool
    {
        return $this->settings->claimsEnabled();
    }

    public function reset(): void
    {
        $this->known = [];
    }
}
