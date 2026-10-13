<?php

declare(strict_types=1);

namespace App\Tests\Shared;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class HealthControllerTest extends WebTestCase
{
    public function testReportsOkWithoutDetailsWhenDatabaseIsReachable(): void
    {
        $client = self::createClient();
        $client->request('GET', '/healthz');

        self::assertResponseIsSuccessful();
        self::assertSame(['status' => 'ok'], json_decode((string) $client->getResponse()->getContent(), true));
        self::assertStringContainsString('no-store', (string) $client->getResponse()->headers->get('Cache-Control'));
    }

    public function testIsPublic(): void
    {
        $client = self::createClient();
        $client->request('HEAD', '/healthz');

        self::assertResponseStatusCodeSame(200);
    }
}
