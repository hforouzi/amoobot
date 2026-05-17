<?php

declare(strict_types=1);

namespace App\Provisioning\Application;

final class VpnConfigLinkSet
{
    /**
     * @param iterable<mixed> $links
     */
    public function filter(iterable $links, ?int $rawCount = null, ?int $formattedCount = null): VpnConfigLinkSetResult
    {
        $rawLinks = [];
        foreach ($links as $link) {
            $candidate = trim((string) $link);
            if ('' !== $candidate) {
                $rawLinks[] = $candidate;
            }
        }

        $byKey = [];
        foreach ($rawLinks as $link) {
            $key = $this->dedupeKey($link);
            if (!isset($byKey[$key]) || $this->isPreferred($link, $byKey[$key])) {
                $byKey[$key] = $link;
            }
        }

        $finalLinks = array_values($byKey);

        return new VpnConfigLinkSetResult(
            rawCount: $rawCount ?? count($rawLinks),
            formattedCount: $formattedCount ?? count(array_filter($rawLinks, fn (string $link): bool => $this->fragmentScore($link) >= 10)),
            finalLinks: $finalLinks,
            droppedDuplicateCount: max(0, count($rawLinks) - count($finalLinks)),
        );
    }

    private function isPreferred(string $candidate, string $existing): bool
    {
        $candidateScore = $this->fragmentScore($candidate);
        $existingScore = $this->fragmentScore($existing);
        if ($candidateScore !== $existingScore) {
            return $candidateScore > $existingScore;
        }

        return mb_strlen($candidate) > mb_strlen($existing);
    }

    private function fragmentScore(string $link): int
    {
        $fragment = rawurldecode((string) (parse_url($link, PHP_URL_FRAGMENT) ?? ''));
        $score = mb_strlen(trim($fragment));
        if (str_contains($fragment, ' ')) {
            $score += 20;
        }
        if (preg_match('/\d+(?:\.\d+)?\s*GB|📊/iu', $fragment) === 1) {
            $score += 30;
        }

        return $score;
    }

    private function dedupeKey(string $link): string
    {
        $scheme = strtolower((string) (parse_url($link, PHP_URL_SCHEME) ?? ''));
        if (!in_array($scheme, ['vless', 'trojan'], true)) {
            $withoutFragment = preg_replace('/#.*$/', '', $link) ?? $link;

            return $scheme.':'.hash('sha256', $withoutFragment);
        }

        $query = [];
        parse_str((string) (parse_url($link, PHP_URL_QUERY) ?? ''), $query);

        return implode('|', [
            $scheme,
            rawurldecode((string) (parse_url($link, PHP_URL_USER) ?? '')),
            strtolower((string) (parse_url($link, PHP_URL_HOST) ?? '')),
            (string) ((int) (parse_url($link, PHP_URL_PORT) ?? 0)),
            (string) (parse_url($link, PHP_URL_PATH) ?? ''),
            strtolower((string) ($query['type'] ?? '')),
            strtolower((string) ($query['security'] ?? '')),
            (string) ($query['path'] ?? ''),
        ]);
    }
}
