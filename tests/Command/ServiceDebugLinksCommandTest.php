<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Tests\Support\IntegrationTestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * app:service:debug-links on the server: where the subscription URL and every config link point (scheme, host,
 * port, publicly usable or not, known defects) without printing the sub id, the client UUID or a password.
 */
final class ServiceDebugLinksCommandTest extends IntegrationTestCase
{
    private const SUB_ID = 'k3j9x7q2m1zt5w8a';
    private const UUID = 'b831381d-6324-4d53-ad4f-8cda48b30811';

    public function testItShowsWhereEverythingPointsWithSecretsMasked(): void
    {
        $service = $this->createActiveService($this->createUser(), $this->createTestInbound())
            ->setSubscriptionUrl('http://127.0.0.1:2096/sub/'.self::SUB_ID.'/'.self::SUB_ID)
            ->setSubId(self::SUB_ID)
            ->setClientUuid(self::UUID)
            ->setRemoteId('1:'.self::UUID.':shop@x:'.self::SUB_ID)
            ->setConfigLinks([
                'vless://'.self::UUID.'@edge.example-shop.com:443?type=tcp&security=reality&pbk=PUBKEY&sid=12#shop',
                'trojan://s3cretpassw0rd@10.0.0.5:8443?sni=x#t',
            ]);
        $this->em->flush();

        [$code, $out] = $this->debugLinks((int) $service->getId());

        self::assertSame(Command::SUCCESS, $code, $out);
        self::assertMatchesRegularExpression('/subscription URL\s+http:\/\/127\.0\.0\.1:2096\/sub\/<subId>\/<subId>/', $out);
        self::assertMatchesRegularExpression('/scheme\s+http\b/', $out);
        self::assertMatchesRegularExpression('/host\s+127\.0\.0\.1/', $out);
        self::assertMatchesRegularExpression('/port\s+2096/', $out);
        self::assertMatchesRegularExpression('/publicly usable\s+NO/', $out);
        self::assertStringContainsString('the sub id appears twice', $out);
        self::assertMatchesRegularExpression('/#1\s+vless\s+edge\.example-shop\.com:443\s+usable yes/', $out);
        self::assertMatchesRegularExpression('/#2\s+trojan\s+10\.0\.0\.5:8443\s+usable NO/', $out);
        self::assertStringContainsString('QR delivery', $out);

        foreach ([self::SUB_ID, self::UUID, 's3cretpassw0rd'] as $secret) {
            self::assertStringNotContainsString($secret, $out);
        }
    }

    public function testAServiceWithoutLinks(): void
    {
        $service = $this->createActiveService($this->createUser(), $this->createTestInbound());

        [$code, $out] = $this->debugLinks((int) $service->getId());

        self::assertSame(Command::SUCCESS, $code);
        self::assertMatchesRegularExpression('/subscription URL\s+\(none\)/', $out);
        self::assertStringContainsString('config links      0', $out);
    }

    /**
     * @return array{int, string}
     */
    private function debugLinks(int $serviceId): array
    {
        $tester = new CommandTester((new Application(self::$kernel))->find('app:service:debug-links'));
        $code = $tester->execute(['serviceId' => (string) $serviceId]);

        return [$code, $tester->getDisplay()];
    }
}
