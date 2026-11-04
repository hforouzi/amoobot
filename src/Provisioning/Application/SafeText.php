<?php

declare(strict_types=1);

namespace App\Provisioning\Application;

use App\Entity\VpnService;
use App\Provisioning\Infrastructure\Sanaei3xui\Modern\Http\SecretRedactor;

/**
 * An error text of the inbound move (docs/design/plan-inbound-change.md 5.4, 6.3) as the console, the logs, the move
 * row and the admin alert may carry it: SecretRedactor::redactAccess() (links, URLs, UUIDs, subId/password pairs, the
 * Telegram id in a client email), with a known service's own subId, UUID and panel base path removed first
 * (ServiceErrorText), the panel's own token and password removed before all that when the caller gives them
 * (SecretRedactor::redact()), and then the client email and the request path that names it (clients/<email>/...) masked too.
 */
final class SafeText
{
    /**
     * @param list<string> $secrets exact values to remove first: the panel's token and password (InboundMovePlanner::panelSecrets())
     */
    public static function of(string $text, string $email = '', int $maxLength = 255, #[\SensitiveParameter] array $secrets = []): string
    {
        return self::withoutClient(SecretRedactor::redactAccess(SecretRedactor::redact($text, $secrets, 4000), 2000), $email, $maxLength);
    }

    /**
     * @param list<string> $secrets as for of(): an exception that is not a panel answer may quote them verbatim
     */
    public static function forService(VpnService $service, string $text, int $maxLength = 255, #[\SensitiveParameter] array $secrets = []): string
    {
        return self::withoutClient(ServiceErrorText::redact($service, SecretRedactor::redact($text, $secrets, 4000), 2000), (string) $service->getClientEmail(), $maxLength);
    }

    private static function withoutClient(string $text, string $email, int $maxLength): string
    {
        $text = (string) preg_replace('#clients/(?!\*\*\*/)[^/\s]+/#', 'clients/***/', $text);
        if ('' !== trim($email)) {
            $text = str_ireplace([$email, rawurlencode($email)], '***', $text);
        }

        return mb_substr($text, 0, $maxLength);
    }
}
