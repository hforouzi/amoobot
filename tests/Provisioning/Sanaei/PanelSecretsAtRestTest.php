<?php

declare(strict_types=1);

namespace App\Tests\Provisioning\Sanaei;

use App\Entity\VpnPanel;
use App\Provisioning\Infrastructure\PanelCredentials;
use App\Shared\Infrastructure\SecretCipher;
use App\Tests\Support\IntegrationTestCase;

/**
 * A token or password typed into the panel form is encrypted before it reaches the database (VpnPanelSecretsListener),
 * on insert and on later edits, and read back only through PanelCredentials.
 */
final class PanelSecretsAtRestTest extends IntegrationTestCase
{
    private const TOKEN = 'db-token-ABCDEFGHIJKLMNOPQRSTUV0123456789';
    private const PASSWORD = 'db-password-xyz-123';

    public function testSecretsAreEncryptedOnInsertAndOnEdit(): void
    {
        $panel = (new VpnPanel())->setType('sanaei_3xui')->setTitle('enc')->setBaseUrl('https://panel.example.test:2053/')
            ->setUsername('admin')->setPlainApiToken(self::TOKEN)->setPlainPassword(self::PASSWORD);
        $this->em->persist($panel);
        $this->em->flush();

        $row = $this->row('vpn_panel', (int) $panel->getId());
        self::assertStringStartsWith(SecretCipher::PREFIX, (string) $row['api_token']);
        self::assertStringStartsWith(SecretCipher::PREFIX, (string) $row['password']);
        self::assertStringNotContainsString(self::TOKEN, (string) json_encode($row));
        self::assertStringNotContainsString(self::PASSWORD, (string) json_encode($row));

        // Edit: only the (unmapped) typed token changes; the listener still runs before change sets are computed.
        $this->em->clear();
        $panel = $this->em->find(VpnPanel::class, $panel->getId());
        self::assertInstanceOf(VpnPanel::class, $panel);
        $panel->setPlainApiToken('db-token-NEW-0000000000000000000000000000');
        $this->em->flush();

        $row = $this->row('vpn_panel', (int) $panel->getId());
        self::assertStringStartsWith(SecretCipher::PREFIX, (string) $row['api_token']);
        self::assertStringNotContainsString('db-token-NEW', (string) json_encode($row));

        $this->em->clear();
        $panel = $this->em->find(VpnPanel::class, $panel->getId());
        self::assertInstanceOf(VpnPanel::class, $panel);
        $credentials = self::getContainer()->get(PanelCredentials::class);
        self::assertSame('db-token-NEW-0000000000000000000000000000', $credentials->apiToken($panel));
        self::assertSame(self::PASSWORD, $credentials->password($panel));
    }
}
