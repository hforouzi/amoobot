<?php

declare(strict_types=1);

namespace App\Tests\Admin;

use App\Entity\AdminUser;
use App\Entity\VpnPanel;
use App\Provisioning\Domain\SanaeiIntegrationMode;
use App\Tests\Support\Fake3xui\Fake3xuiPanel;
use App\Tests\Support\IntegrationTestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * The one panel form (PanelConnectionType through PanelSetupController) for create and edit: title, URL, token,
 * active, the advanced settings, and the "Legacy credentials" block only for a panel on the old API.
 */
final class PanelFormTest extends IntegrationTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->em->getConnection()->executeStatement('DELETE FROM admin_user');
        $admin = (new AdminUser())->setEmail('panel-form@shop.test')->setPassword('unused')->setRoles([AdminUser::ROLE_SUPER_ADMIN])->setIsActive(true);
        $this->em->persist($admin);
        $this->em->flush();

        $this->client = new KernelBrowser(self::$kernel);
        $this->client->disableReboot();
        $this->client->loginUser($admin, 'main');
    }

    public function testAModernPanelIsCreatedAndEditedThroughTheForm(): void
    {
        $crawler = $this->client->request('GET', '/admin/panel-setup/new');
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertCount(1, $crawler->filter('input[name="panel_connection[isActive]"]'));
        self::assertCount(1, $crawler->filter('details input[name="panel_connection[publicHost]"]'), 'public host is an advanced setting');
        self::assertCount(0, $crawler->filter('#legacy-credentials, [name*="username"], [name*="plainPassword"], [name*="apiVersion"], [name*="authMode"], [name*="clientApiMode"], [name*="basePath"], [name*="[type]"]'));

        $form = $crawler->filter('form')->form([
            'panel_connection[title]' => 'Main panel',
            'panel_connection[baseUrl]' => Fake3xuiPanel::ORIGIN.'/secret/',
            'panel_connection[plainApiToken]' => Fake3xuiPanel::TOKEN,
            'panel_connection[subscriptionBaseUrl]' => 'https://sub.example.test:2096',
            'panel_connection[subscriptionPathPrefix]' => '/feed',
            'panel_connection[publicHost]' => 'edge.example.test',
        ]);
        $form['panel_connection[isActive]']->untick();
        $this->client->submit($form);
        self::assertTrue($this->client->getResponse()->isRedirect(), (string) $this->client->getResponse()->getStatusCode());

        $panel = $this->reload($this->em->getRepository(VpnPanel::class)->findOneBy(['title' => 'Main panel']));
        self::assertSame('sanaei_3xui', $panel->getType());
        self::assertFalse($panel->isActive(), 'the active flag is saved');
        self::assertTrue($panel->isApiTokenConfigured());
        self::assertSame('https://sub.example.test:2096', $panel->getSubscriptionBaseUrl());
        self::assertSame('/feed', $panel->getSubscriptionPathPrefix());
        self::assertSame('edge.example.test', $panel->getPublicHost());
        self::assertArrayNotHasKey('subscription_base_url', (array) $panel->getConfig(), 'the subscription base URL is stored once (column)');
        $storedToken = $panel->getStoredApiToken();

        $crawler = $this->client->request('GET', '/admin/panel-setup/'.$panel->getId().'/edit');
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertCount(0, $crawler->filter('#legacy-credentials'));
        $form = $crawler->filter('form')->form(['panel_connection[title]' => 'Main panel (renamed)']);
        $form['panel_connection[isActive]']->tick();
        $this->client->submit($form);
        self::assertTrue($this->client->getResponse()->isRedirect(), (string) $this->client->getResponse()->getStatusCode());

        $panel = $this->reload($panel);
        self::assertSame('Main panel (renamed)', $panel->getTitle());
        self::assertTrue($panel->isActive());
        self::assertSame($storedToken, $panel->getStoredApiToken(), 'an empty token field keeps the stored token');
        self::assertSame('edge.example.test', $panel->getPublicHost());
    }

    public function testTheOldDuplicatedSubscriptionKeyIsReadAndMovedToTheColumnOnSave(): void
    {
        $panel = $this->tokenPanel('Old panel');
        $panel->setConfig(['subscription_base_url' => 'https://old-sub.example.test', 'subscription_path_prefix' => '/sub']);
        $this->em->flush();

        $crawler = $this->client->request('GET', '/admin/panel-setup/'.$panel->getId().'/edit');
        self::assertSame('https://old-sub.example.test', $crawler->filter('input[name="panel_connection[subscriptionBaseUrl]"]')->attr('value'));
        $this->client->submit($crawler->filter('form')->form());
        self::assertTrue($this->client->getResponse()->isRedirect(), (string) $this->client->getResponse()->getStatusCode());

        $row = $this->em->getConnection()->fetchAssociative('SELECT subscription_base_url, config FROM vpn_panel WHERE id = ?', [$panel->getId()]);
        self::assertIsArray($row);
        self::assertSame('https://old-sub.example.test', $row['subscription_base_url']);
        $config = json_decode((string) $row['config'], true);
        self::assertArrayNotHasKey('subscription_base_url', $config);
        self::assertSame('/sub', $config['subscription_path_prefix']);
    }

    public function testALegacyPanelIsStillEditableWithItsCredentialsBlock(): void
    {
        $panel = (new VpnPanel())->setType('sanaei_3xui')->setTitle('Old 3x-ui')->setBaseUrl('https://old.example.test:2053')
            ->setIntegrationMode(SanaeiIntegrationMode::LEGACY)->setUsername('admin')->setPlainPassword('legacy-pass-7c1e')
            ->setApiVersion('legacy')->setAuthMode('cookie')->setClientApiMode('inbound_legacy')->setBasePath('/xui');
        $this->em->persist($panel);
        $this->em->flush();
        $storedPassword = $this->reload($panel)->getStoredPassword();

        $crawler = $this->client->request('GET', '/admin/panel-setup/'.$panel->getId().'/edit');
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertStringNotContainsString('legacy-pass-7c1e', (string) $this->client->getResponse()->getContent());
        $block = $crawler->filter('#legacy-credentials');
        self::assertCount(1, $block);
        foreach (['username', 'plainPassword', 'apiVersion', 'authMode', 'clientApiMode', 'basePath'] as $field) {
            self::assertCount(1, $block->filter('[name="panel_connection['.$field.']"]'), $field);
        }
        self::assertSame('admin', $block->filter('input[name="panel_connection[username]"]')->attr('value'));

        // No API token: the stored username and password are enough, and an empty password field keeps the password.
        $this->client->submit($crawler->filter('form')->form([
            'panel_connection[title]' => 'Old 3x-ui (renamed)',
            'panel_connection[apiVersion]' => 'v3',
        ]));
        self::assertTrue($this->client->getResponse()->isRedirect(), (string) $this->client->getResponse()->getStatusCode());

        $panel = $this->reload($panel);
        self::assertSame('Old 3x-ui (renamed)', $panel->getTitle());
        self::assertSame('v3', $panel->getApiVersion());
        self::assertSame('admin', $panel->getUsername());
        self::assertSame($storedPassword, $panel->getStoredPassword());
        self::assertSame('/xui', $panel->getBasePath());
        self::assertFalse($panel->isApiTokenConfigured());
        self::assertSame(SanaeiIntegrationMode::LEGACY, $panel->getIntegrationMode());
    }

    public function testEditingKeepsThePanelType(): void
    {
        $panel = $this->tokenPanel('Dev panel')->setType('dummy');
        $this->em->flush();

        $crawler = $this->client->request('GET', '/admin/panel-setup/'.$panel->getId().'/edit');
        $this->client->submit($crawler->filter('form')->form(['panel_connection[title]' => 'Dev panel 2']));
        self::assertTrue($this->client->getResponse()->isRedirect(), (string) $this->client->getResponse()->getStatusCode());

        self::assertSame('dummy', $this->reload($panel)->getType());
    }

    public function testTheClassicFormUrlsLeadToThisForm(): void
    {
        $panel = $this->tokenPanel('Any');

        $this->client->request('GET', '/admin/vpn-panel/new');
        self::assertTrue($this->client->getResponse()->isRedirect('/admin/panel-setup/new'));
        $this->client->request('GET', '/admin/vpn-panel/'.$panel->getId().'/edit');
        self::assertTrue($this->client->getResponse()->isRedirect('/admin/panel-setup/'.$panel->getId().'/edit'));

        $crawler = $this->client->request('GET', '/admin/vpn-panel');
        self::assertCount(1, $crawler->filter('a[href*="routeName=admin_panel_setup_edit"][href*="'.urlencode('routeParams[id]').'='.$panel->getId().'"]'), 'the list links to the guided edit');
    }

    private function tokenPanel(string $title): VpnPanel
    {
        $panel = (new VpnPanel())->setType('sanaei_3xui')->setTitle($title)->setBaseUrl(Fake3xuiPanel::ORIGIN.'/secret/')->setPlainApiToken(Fake3xuiPanel::TOKEN);
        $this->em->persist($panel);
        $this->em->flush();

        return $panel;
    }

    private function reload(?VpnPanel $panel): VpnPanel
    {
        self::assertInstanceOf(VpnPanel::class, $panel);
        $this->em->clear();
        $reloaded = $this->em->find(VpnPanel::class, $panel->getId());
        self::assertInstanceOf(VpnPanel::class, $reloaded);

        return $reloaded;
    }
}
