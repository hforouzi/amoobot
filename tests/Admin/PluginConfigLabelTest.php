<?php

declare(strict_types=1);

namespace App\Tests\Admin;

use App\Admin\Form\ConfigFieldLabel;
use App\Entity\AdminUser;
use App\Entity\Plugin;
use App\Tests\Support\IntegrationTestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Gateway config labels on the schema form (ConfigFieldLabel). A plugin field whose label has no entry for the admin's
 * language used to show the raw key ("payment_gateway.config.merchant_id") on the Persian admin: the plugin's label in
 * the language is used first, then the app's translation, then the plugin's English label, then the name made readable.
 */
final class PluginConfigLabelTest extends IntegrationTestCase
{
    private const CODE = 'demo_payment_gateway';

    protected function setUp(): void
    {
        parent::setUp();
        $connection = $this->em->getConnection();
        $connection->executeStatement('DELETE FROM plugin WHERE code = ?', [self::CODE]);
        $connection->executeStatement('DELETE FROM admin_user');
    }

    protected function tearDown(): void
    {
        $this->em->getConnection()->executeStatement('DELETE FROM plugin WHERE code = ?', [self::CODE]);
        parent::tearDown();
    }

    public function testTheLabelIsResolvedInOrder(): void
    {
        $translator = self::getContainer()->get(TranslatorInterface::class);

        self::assertSame('Merchant ID', (new ConfigFieldLabel('merchant_id', ['en' => 'Merchant ID']))->trans($translator, 'fa'), 'English label on the Persian admin');
        self::assertSame('شناسه پذیرنده', (new ConfigFieldLabel('merchant_id', ['en' => 'Merchant ID', 'fa' => 'شناسه پذیرنده']))->trans($translator, 'fa'));
        self::assertSame('Merchant ID', (new ConfigFieldLabel('merchant_id', ['en' => 'Merchant ID', 'fa' => 'شناسه پذیرنده']))->trans($translator, 'en'));
        self::assertSame('Merchant ID', (new ConfigFieldLabel('merchant_id', 'Merchant ID'))->trans($translator, 'fa'), 'a single string');
        self::assertSame('Händler', (new ConfigFieldLabel('merchant_id', ['de' => 'Händler']))->trans($translator, 'fa'), 'another language when there is no English');
        self::assertSame('Merchant id', (new ConfigFieldLabel('merchant_id'))->trans($translator, 'fa'), 'no label at all');
        self::assertSame('Merchant id', (new ConfigFieldLabel('merchant_id', ['en' => '  ', 'fa' => 42]))->trans($translator, 'fa'), 'empty or non-text labels are ignored');
        self::assertSame('Webhook signing key', (new ConfigFieldLabel('webhook-signing.key'))->trans($translator, 'en'));

        // Core fields keep the app's own translation, in each language, unless the plugin labels them in that language.
        self::assertSame('کلید API', (new ConfigFieldLabel('api_key', ['en' => 'API token']))->trans($translator, 'fa'));
        self::assertSame('API token', (new ConfigFieldLabel('api_key', ['en' => 'API token']))->trans($translator, 'en'));
        self::assertSame('کلید API', (new ConfigFieldLabel('api_key'))->trans($translator, 'fa'));
    }

    public function testAPluginWithEnglishOnlyLabelsShowsThemOnThePersianForm(): void
    {
        $this->installPlugin([
            ['key' => 'merchant_id', 'type' => 'text', 'required' => true, 'label' => ['en' => 'Merchant ID']],
            ['key' => 'api_key', 'type' => 'password', 'required' => false, 'label' => ['en' => 'API token']],
            ['key' => 'sandbox_mode', 'type' => 'boolean', 'required' => false, 'label' => ['en' => 'Sandbox mode']],
            ['key' => 'mode', 'type' => 'choice', 'required' => false, 'default' => 'live', 'label' => ['en' => 'Mode'], 'choices' => ['Live' => 'live', 'Sandbox' => 'sandbox']],
            ['key' => 'max_amount_rial', 'type' => 'integer', 'required' => false],
        ]);
        $client = $this->client();

        $client->request('GET', '/admin/locale/fa', ['redirect_to' => '/admin']);
        $crawler = $client->request('GET', '/admin/payment-gateways/install/'.self::CODE);
        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertSame([
            'form_merchant_id' => 'Merchant ID',
            'form_api_key' => 'کلید API',
            'form_sandbox_mode' => 'Sandbox mode',
            'form_mode' => 'Mode',
            'form_max_amount_rial' => 'Max amount rial',
        ], $this->labels($crawler));
        self::assertStringNotContainsString('payment_gateway.config.', (string) $client->getResponse()->getContent());

        $client->request('GET', '/admin/locale/en', ['redirect_to' => '/admin']);
        $crawler = $client->request('GET', '/admin/payment-gateways/install/'.self::CODE);
        self::assertSame('API token', $this->labels($crawler)['form_api_key']);
        self::assertStringNotContainsString('payment_gateway.config.', (string) $client->getResponse()->getContent());
    }

    /**
     * @return array<string, string> config field id => its label text
     */
    private function labels(Crawler $crawler): array
    {
        $labels = [];
        foreach (['form_merchant_id', 'form_api_key', 'form_sandbox_mode', 'form_mode', 'form_max_amount_rial'] as $id) {
            $labels[$id] = trim(preg_replace('/\s+/u', ' ', $crawler->filter('label[for="'.$id.'"]')->text()) ?? '');
        }

        return $labels;
    }

    /**
     * The demo plugin from docs/plugins, registered as installed with the given configSchema in its manifest.
     *
     * @param list<array<string, mixed>> $schema
     */
    private function installPlugin(array $schema): void
    {
        $path = dirname(__DIR__, 2).'/docs/plugins/demo-payment-gateway';
        $manifest = json_decode((string) file_get_contents($path.'/plugin.json'), true, flags: JSON_THROW_ON_ERROR);
        $manifest['configSchema'] = $schema;
        $plugin = (new Plugin())
            ->setCode(self::CODE)
            ->setType(Plugin::TYPE_PAYMENT_GATEWAY)
            ->setName(['en' => 'Demo'])
            ->setVersion((string) ($manifest['version'] ?? '1.0.0'))
            ->setPath($path)
            ->setMainClass((string) $manifest['mainClass'])
            ->setManifest($manifest)
            ->setStatus(Plugin::STATUS_ENABLED);
        $this->em->persist($plugin);
        $this->em->flush();
    }

    private function client(): KernelBrowser
    {
        $admin = (new AdminUser())->setEmail('labels@shop.test')->setPassword('unused')->setRoles([AdminUser::ROLE_SUPER_ADMIN])->setIsActive(true);
        $this->em->persist($admin);
        $this->em->flush();
        $client = new KernelBrowser(self::$kernel);
        $client->disableReboot();
        $client->loginUser($admin, 'main');

        return $client;
    }
}
