<?php

declare(strict_types=1);

namespace App\MiniApp\Admin;

use App\MiniApp\Security\MiniAppUser;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The server's half of the confirmation step of money and destructive admin actions. The first call of such an action
 * gets 428 with a token and the text to confirm (AdminActions); the app shows Telegram's confirm dialog and only on
 * "OK" repeats the call with the token in X-Admin-Confirm. The token is an HMAC bound to this session, the action
 * (route) and its target, valid for TTL_SECONDS; nothing is stored. The customer's "pay from wallet" uses the same
 * step (MiniAppCheckoutController::payFromWallet()).
 */
final class AdminConfirmTokens
{
    public const HEADER = 'X-Admin-Confirm';
    public const TTL_SECONDS = 300;

    public function __construct(
        private readonly ClockInterface $clock,
        #[\SensitiveParameter]
        #[Autowire('%env(APP_SECRET)%')]
        private readonly string $appSecret,
    ) {
    }

    public function issue(MiniAppUser $user, string $route, int $target): string
    {
        $expires = $this->clock->now()->getTimestamp() + self::TTL_SECONDS;

        return $expires.'.'.$this->sign($user, $route, $target, $expires);
    }

    public function isValid(MiniAppUser $user, string $route, int $target, ?string $token): bool
    {
        if (null === $token || 1 !== preg_match('/^(\d{10})\.([A-Za-z0-9_-]{43})$/', $token, $m)) {
            return false;
        }
        $expires = (int) $m[1];
        if ($expires < $this->clock->now()->getTimestamp()) {
            return false;
        }

        return hash_equals($this->sign($user, $route, $target, $expires), $m[2]);
    }

    private function sign(MiniAppUser $user, string $route, int $target, int $expires): string
    {
        $data = implode('|', ['miniapp-admin-confirm', $user->session->getTokenHash(), $user->telegramUserId(), $route, $target, $expires]);

        return rtrim(strtr(base64_encode(hash_hmac('sha256', $data, $this->appSecret, true)), '+/', '-_'), '=');
    }
}
