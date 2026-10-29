<?php

declare(strict_types=1);

namespace App\Provisioning\Application;

use App\Entity\VpnService;
use App\Provisioning\Infrastructure\Sanaei3xui\Modern\Http\PanelEndpoint;
use App\Provisioning\Infrastructure\Sanaei3xui\Modern\Http\SecretRedactor;
use App\Provisioning\Infrastructure\Sanaei3xui\Sanaei3xuiRemoteIdParser;

/**
 * An error text about one service as logs, command output, admin flashes and verify-links may show it: the service's
 * subscription id, client UUID, panel base path and every URL are removed directly, whatever produced the text (a
 * panel call, a database exception quoting its parameters, a driver).
 */
final class ServiceErrorText
{
    public static function redact(VpnService $service, string $text, int $maxLength = 300): string
    {
        $secrets = [(string) $service->getSubId(), (string) $service->getClientUuid()];
        $remoteSubId = self::remoteIdPart((string) $service->getRemoteId());
        if (null !== $remoteSubId) {
            $secrets[] = $remoteSubId;
        }
        $panel = $service->getPanel();
        if (null !== $panel) {
            try {
                $basePath = trim(PanelEndpoint::fromPanel($panel)->basePath, '/');
                if ('' !== $basePath) {
                    $secrets[] = $basePath;
                    array_push($secrets, ...explode('/', $basePath));
                }
            } catch (\RuntimeException) {
                // An unusable panel URL has no base path to hide.
            }
        }
        $text = (string) preg_replace('#\b[a-z][a-z0-9+.-]*://[^\s"\'<>]*#i', '<url>', $text);
        $secrets = array_values(array_unique(array_filter(array_map('trim', $secrets), static fn (string $s): bool => '' !== $s)));
        usort($secrets, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));
        foreach ($secrets as $secret) {
            foreach (array_unique([$secret, rawurlencode($secret)]) as $form) {
                $text = (string) preg_replace('/(?<![A-Za-z0-9_.~-])'.preg_quote($form, '/').'(?![A-Za-z0-9_.~-])/i', SecretRedactor::MASK, $text);
            }
        }

        return SecretRedactor::redact($text, [], $maxLength);
    }

    /** The subscription id a 3x-ui remote id carries (it can differ from the stored column). */
    private static function remoteIdPart(string $remoteId): ?string
    {
        $ref = (new Sanaei3xuiRemoteIdParser())->parse($remoteId);

        return null === $ref || null === $ref->subId || '' === trim($ref->subId) ? null : trim($ref->subId);
    }
}
