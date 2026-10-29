<?php

declare(strict_types=1);

namespace App\Tests\Provisioning;

use App\Provisioning\Domain\ShareLinkPolicy;
use PHPUnit\Framework\TestCase;

/**
 * ShareLinkPolicy::filter() (Hosts H0): repeated items are dropped before the 500-link cap, so duplicates in a panel's
 * answer never push distinct links out of the snapshot.
 */
final class ShareLinkPolicyTest extends TestCase
{
    public function testDuplicatesAreDroppedBeforeTheCap(): void
    {
        $unique = [];
        for ($i = 0; $i < 600; ++$i) {
            $unique[] = sprintf('vless://00000000-2222-4333-8444-555555555555@h%d.example.com:443#n%d', $i, $i);
        }
        $answer = [...array_fill(0, 499, $unique[0]), ...$unique];

        $result = ShareLinkPolicy::filter($answer);

        self::assertCount(ShareLinkPolicy::MAX_LINKS, $result['links']);
        self::assertSame(array_slice($unique, 0, ShareLinkPolicy::MAX_LINKS), $result['links'], 'the first 500 distinct links, in panel order');
        self::assertSame(100, $result['rejected'], 'only the distinct links beyond the cap are rejected');
    }

    public function testATextAnswerIsDedupedAndKeepsOrder(): void
    {
        $result = ShareLinkPolicy::filter("vless://a@b.example.com:1\r\nvless://a@c.example.com:1\nvless://a@b.example.com:1\n\n");

        self::assertSame(['vless://a@b.example.com:1', 'vless://a@c.example.com:1'], $result['links']);
        self::assertSame(0, $result['rejected']);
    }
}
