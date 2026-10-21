<?php

declare(strict_types=1);

namespace App\Provisioning\Domain;

/**
 * Undoes the damage done to stored subscription URLs by appending the sub id (or a slash) to a URL the driver had
 * already completed: ".../sub/<id>/<id>" becomes ".../sub/<id>", ".../sub/<id>/" becomes ".../sub/<id>", and a
 * relative "/<id>" (no subscription base configured) becomes null.
 */
final class SubscriptionUrlRepair
{
    public static function repair(?string $url, ?string $subId): ?string
    {
        $url = trim((string) $url);
        if ('' === $url || !preg_match('#^https?://#i', $url)) {
            return null;
        }

        $url = rtrim($url, '/');
        $subId = trim((string) $subId);
        if ('' !== $subId) {
            foreach (array_unique([$subId, rawurlencode($subId)]) as $id) {
                $segment = '/'.$id;
                if (str_ends_with($url, $segment.$segment)) {
                    $url = substr($url, 0, -strlen($segment));
                    break;
                }
            }
        }

        return $url;
    }
}
