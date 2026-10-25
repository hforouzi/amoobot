<?php

declare(strict_types=1);

namespace App\Tests\Reseller\Claim;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Every claim-link text exists in Persian and English, with the same placeholders.
 */
final class ClaimTranslationsTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function domains(): iterable
    {
        yield 'bot' => ['bot', '/^(claim\.|button\.claim\.|reseller\.claim|reseller\.claimed_on|button\.reseller\.(claim_links|make_claim_link|new_claim_link)|service\.(renewed|add_traffic_done)_for_customer)/'];
        yield 'miniapp' => ['miniapp', '/^claim\./'];
        yield 'admin' => ['admin', '/^(claim_admin\.|reseller_settings\.(fields|help)\.claim|reseller_settings\.sections\.claims|admin\.claims$|action\.claims_history$|flash\.settings\.reseller_claim_ttl$)/'];
    }

    #[DataProvider('domains')]
    public function testPersianAndEnglishHaveTheSameKeysAndPlaceholders(string $domain, string $pattern): void
    {
        $dir = dirname(__DIR__, 3).'/translations/';
        $fa = self::flatten(Yaml::parseFile($dir.$domain.'.fa.yaml'));
        $en = self::flatten(Yaml::parseFile($dir.$domain.'.en.yaml'));
        $faKeys = array_values(array_filter(array_keys($fa), static fn (string $k): bool => 1 === preg_match($pattern, $k)));
        $enKeys = array_values(array_filter(array_keys($en), static fn (string $k): bool => 1 === preg_match($pattern, $k)));
        sort($faKeys);
        sort($enKeys);

        self::assertNotSame([], $faKeys);
        self::assertSame($faKeys, $enKeys);
        foreach ($faKeys as $key) {
            self::assertSame(self::placeholders($fa[$key]), self::placeholders($en[$key]), $domain.': '.$key);
        }
    }

    /**
     * @param array<mixed> $tree
     *
     * @return array<string, string>
     */
    private static function flatten(array $tree, string $prefix = ''): array
    {
        $flat = [];
        foreach ($tree as $key => $value) {
            $name = '' === $prefix ? (string) $key : $prefix.'.'.$key;
            if (is_array($value)) {
                $flat += self::flatten($value, $name);
            } else {
                $flat[$name] = (string) $value;
            }
        }

        return $flat;
    }

    /**
     * @return list<string>
     */
    private static function placeholders(string $text): array
    {
        preg_match_all('/\{\{\s*[a-zA-Z.]+\s*\}\}|%[a-z_]+%/', $text, $m);
        $found = array_map(static fn (string $p): string => (string) preg_replace('/\s+/', '', $p), $m[0]);
        sort($found);

        return array_values(array_unique($found));
    }
}
