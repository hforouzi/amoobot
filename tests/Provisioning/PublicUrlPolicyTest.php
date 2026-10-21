<?php

declare(strict_types=1);

namespace App\Tests\Provisioning;

use App\Provisioning\Domain\PublicUrlPolicy;
use App\Provisioning\Domain\SubscriptionUrlRepair;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * What may be handed to a customer (subscription URL, config links, QR codes): only addresses a phone on the internet
 * can reach. And the repair of subscription URLs stored with the duplicated sub id / trailing slash.
 */
final class PublicUrlPolicyTest extends TestCase
{
    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function urls(): iterable
    {
        yield 'https domain with port' => ['https://sub.example.com:2096/sub/abc123', true];
        yield 'http public domain' => ['http://sub.example.com/sub/abc123', true];
        yield 'public ip' => ['https://8.8.8.8:2096/sub/abc', true];
        yield 'loopback' => ['http://127.0.0.1:2096/sub/abc', false];
        yield 'loopback range' => ['http://127.10.0.5/sub/abc', false];
        yield 'localhost' => ['http://localhost:2096/sub/abc', false];
        yield 'localhost subdomain' => ['http://panel.localhost/sub/abc', false];
        yield 'private 10' => ['https://10.0.0.5/sub/abc', false];
        yield 'private 172.16' => ['https://172.16.3.4/sub/abc', false];
        yield 'private 192.168' => ['https://192.168.1.10:2096/sub/abc', false];
        yield 'link-local' => ['https://169.254.1.1/sub/abc', false];
        yield 'cgnat' => ['https://100.64.0.1/sub/abc', false];
        yield 'unspecified' => ['https://0.0.0.0/sub/abc', false];
        yield 'ipv6 loopback' => ['https://[::1]:2096/sub/abc', false];
        yield 'ipv6 unique local' => ['https://[fd12:3456::1]/sub/abc', false];
        yield 'ipv6 link-local' => ['https://[fe80::1]/sub/abc', false];
        yield 'ipv6 public' => ['https://[2606:4700::1111]/sub/abc', true];
        yield 'mdns name' => ['https://panel.local/sub/abc', false];
        yield 'internal name' => ['https://sub.corp.internal/sub/abc', false];
        yield 'single label' => ['https://panel/sub/abc', false];
        yield 'relative' => ['/abc123', false];
        yield 'no host' => ['https:///sub/abc', false];
        yield 'other scheme' => ['ftp://sub.example.com/sub/abc', false];
        yield 'empty' => ['', false];
    }

    #[DataProvider('urls')]
    public function testSubscriptionUrls(string $url, bool $usable): void
    {
        self::assertSame($usable, PublicUrlPolicy::isPubliclyUsableUrl($url), $url);
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function configLinks(): iterable
    {
        $vmess = static fn (string $host): string => 'vmess://'.base64_encode((string) json_encode(['v' => '2', 'add' => $host, 'port' => '443', 'id' => 'b831381d-6324-4d53-ad4f-8cda48b30811']));

        yield 'vless public' => ['vless://b831381d-6324-4d53-ad4f-8cda48b30811@edge.example.com:443?type=tcp&security=reality&pbk=abc&sid=12#shop', true];
        yield 'vless loopback' => ['vless://b831381d-6324-4d53-ad4f-8cda48b30811@127.0.0.1:443?type=tcp#shop', false];
        yield 'vless private' => ['vless://uuid@192.168.1.2:443#x', false];
        yield 'trojan public' => ['trojan://secret@edge.example.com:443?sni=edge.example.com#x', true];
        yield 'ss public' => ['ss://YWVzLTI1Ni1nY206cGFzcw@edge.example.com:8388#x', true];
        yield 'vmess public' => [$vmess('edge.example.com'), true];
        yield 'vmess private' => [$vmess('10.0.0.9'), false];
        yield 'garbage' => ['vless://', false];
        yield 'subscription url as link' => ['https://sub.example.com/sub/abc', true];
    }

    #[DataProvider('configLinks')]
    public function testConfigLinks(string $link, bool $usable): void
    {
        self::assertSame($usable, PublicUrlPolicy::isPubliclyUsableLink($link), $link);
    }

    /**
     * @return iterable<string, array{?string, ?string, ?string}>
     */
    public static function storedUrls(): iterable
    {
        yield 'duplicated sub id' => ['https://sub.example.com:2096/sub/abc123/abc123', 'abc123', 'https://sub.example.com:2096/sub/abc123'];
        yield 'trailing slash' => ['https://sub.example.com:2096/sub/abc123/', 'abc123', 'https://sub.example.com:2096/sub/abc123'];
        yield 'relative (no base)' => ['/abc123', 'abc123', null];
        yield 'only a slash' => ['/', 'abc123', null];
        yield 'correct' => ['https://sub.example.com:2096/sub/abc123', 'abc123', 'https://sub.example.com:2096/sub/abc123'];
        yield 'custom path kept' => ['https://sub.example.com/feed/abc123', 'abc123', 'https://sub.example.com/feed/abc123'];
        yield 'null' => [null, 'abc123', null];
        yield 'no sub id' => ['https://sub.example.com/sub/x/', null, 'https://sub.example.com/sub/x'];
    }

    #[DataProvider('storedUrls')]
    public function testRepairOfStoredSubscriptionUrls(?string $stored, ?string $subId, ?string $expected): void
    {
        self::assertSame($expected, SubscriptionUrlRepair::repair($stored, $subId));
    }
}
