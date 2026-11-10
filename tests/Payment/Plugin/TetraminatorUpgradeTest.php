<?php

declare(strict_types=1);

namespace App\Tests\Payment\Plugin;

use App\Entity\AdminUser;
use App\Entity\Plugin;
use App\Tests\Support\IntegrationTestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Process\Process;

/**
 * Tetraminator 1.0.0 -> the shipped version (1.1.x) through app:plugin:upgrade, which is docs/plugins/tetraminator-gateway/build.sh's ZIP; the
 * 1.0.0 fixture is the same ZIP with the 1.0.0 manifest (version, no link settings) and a marker constant in the main
 * class, so the test can tell which class a fresh PHP process loads. Installed into var/plugins/tetraminator of this
 * checkout for the test only, and removed again.
 */
final class TetraminatorUpgradeTest extends IntegrationTestCase
{
    private const CODE = 'tetraminator';
    private const MAIN_FILE = 'src/TetraminatorGatewayPlugin.php';

    private string $projectDir;
    private string $installDir;
    /** @var list<string> */
    private array $zips = [];
    private bool $installedHere = false;

    public static function setUpBeforeClass(): void
    {
        // As TetraminatorPackageTest: the classes from docs/ are declared once, so the doctor never declares the ZIP's
        // copies a second time in this process (the upgrader then checks the new files in a separate PHP process).
        foreach (['TetraminatorException', 'TetraminatorApi', 'TetraminatorGatewayPlugin'] as $class) {
            require_once dirname(__DIR__, 3).'/docs/plugins/tetraminator-gateway/src/'.$class.'.php';
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->projectDir = (string) self::getContainer()->getParameter('kernel.project_dir');
        $this->installDir = $this->projectDir.'/var/plugins/'.self::CODE;
        if (file_exists($this->installDir)) {
            self::markTestSkipped($this->installDir.' exists already: not touched by this test.');
        }
        $this->em->getConnection()->executeStatement('DELETE FROM plugin WHERE code = ?', [self::CODE]);
    }

    protected function tearDown(): void
    {
        foreach ($this->zips as $zip) {
            @unlink($zip);
        }
        if ($this->installedHere) {
            $paths = array_merge([$this->installDir], glob($this->projectDir.'/var/plugins/.upgrade-backup/'.self::CODE.'-*') ?: []);
            (new Process(array_merge(['rm', '-rf'], $paths)))->mustRun();
        }
        if (isset($this->em) && $this->em->isOpen()) {
            $this->em->getConnection()->executeStatement('DELETE FROM plugin WHERE code = ?', [self::CODE]);
        }
        parent::tearDown();
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array{int, string}
     */
    private function console(string $command, array $input): array
    {
        $application = new Application(self::$kernel ?? throw new \LogicException('no kernel'));
        $tester = new CommandTester($application->find($command));
        $status = $tester->execute($input);

        return [$status, (string) preg_replace('/\s+/', '', $tester->getDisplay())];
    }

    private function buildZip(): string
    {
        $zip = sys_get_temp_dir().'/amoobot-tetraminator-'.bin2hex(random_bytes(4)).'.zip';
        $this->zips[] = $zip;
        (new Process(['sh', $this->projectDir.'/docs/plugins/tetraminator-gateway/build.sh', $zip]))->mustRun();

        return $zip;
    }

    /** build.sh's ZIP turned into 1.0.0: its manifest as 1.0.0 had it, and the marker in the main class. */
    private function version100(): string
    {
        $zip = $this->buildZip();
        $archive = new \ZipArchive();
        self::assertTrue($archive->open($zip));
        $manifest = json_decode((string) $archive->getFromName('plugin.json'), true, flags: JSON_THROW_ON_ERROR);
        $manifest['version'] = '1.0.0';
        $manifest['configSchema'] = array_values(array_filter($manifest['configSchema'], static fn (array $field): bool => !in_array($field['key'], ['prefer_website_link', 'use_mini_app_link'], true)));
        $archive->addFromString('plugin.json', (string) json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $source = (string) $archive->getFromName(self::MAIN_FILE);
        $marked = str_replace("    public const CODE = 'tetraminator';", "    public const CODE = 'tetraminator';\n    public const FIXTURE_V100 = true;", $source);
        self::assertNotSame($source, $marked);
        $archive->addFromString(self::MAIN_FILE, $marked);
        $archive->close();

        return $zip;
    }

    /** The version in the shipped manifest, docs/plugins/tetraminator-gateway/plugin.json. */
    private function shippedVersion(): string
    {
        $manifest = json_decode((string) file_get_contents($this->projectDir.'/docs/plugins/tetraminator-gateway/plugin.json'), true, flags: JSON_THROW_ON_ERROR);

        return (string) $manifest['version'];
    }

    /** What a fresh PHP process finds in var/plugins/tetraminator: the 1.0.0 marker, or 1.1.x's linkOrder() ("new"). */
    private function liveClass(): string
    {
        $script = <<<'PHP'
            [, $autoload, $src] = $argv;
            require $autoload;
            spl_autoload_register(static function (string $class) use ($src): void {
                $prefix = 'Amoobot\\Plugin\\Tetraminator\\';
                if (str_starts_with($class, $prefix)) {
                    require $src.'/'.substr($class, strlen($prefix)).'.php';
                }
            });
            $class = 'Amoobot\\Plugin\\Tetraminator\\TetraminatorGatewayPlugin';
            echo defined($class.'::FIXTURE_V100') ? '1.0.0' : (method_exists($class, 'linkOrder') ? 'new' : 'unknown');
            PHP;
        $process = new Process([PHP_BINARY, '-r', $script, '--', $this->projectDir.'/vendor/autoload.php', $this->installDir.'/src']);
        $process->mustRun();

        return $process->getOutput();
    }

    /** @return list<string> the config fields the admin gateway form shows */
    private function formFields(KernelBrowser $client, int $gatewayId): array
    {
        $client->request('GET', '/admin/payment-gateways/'.$gatewayId.'/config');
        self::assertSame(200, $client->getResponse()->getStatusCode());
        $names = (new Crawler((string) $client->getResponse()->getContent()))->filter('form [name^="form["]')->each(static fn (Crawler $node): string => (string) $node->attr('name'));

        return array_values(array_unique($names));
    }

    public function testUpgradingTo110LoadsTheNewClassAndShowsTheNewConfigFields(): void
    {
        [$status, $output] = $this->console('app:plugin:install', ['zipPath' => $this->version100()]);
        $this->installedHere = is_dir($this->installDir);
        self::assertSame(Command::SUCCESS, $status, $output);
        [$status, $output] = $this->console('app:plugin:enable', ['code' => self::CODE]);
        self::assertSame(Command::SUCCESS, $status, $output);
        $config = ['enabled' => false, 'api_key' => 'FAKE-tetraminator-key-for-upgrade-test', 'callback_base_url' => 'https://shop.example', 'send_chat_id' => false];
        $gateway = $this->createGateway(self::CODE, $config, 'IRT')->setPluginCode(self::CODE)->setTitle('Tetraminator'); // as the admin installer creates it
        $this->em->flush();
        $gatewayId = (int) $gateway->getId();
        $configBefore = $this->em->getConnection()->fetchOne('SELECT config FROM payment_gateway WHERE id = ?', [$gatewayId]);

        $this->em->getConnection()->executeStatement('DELETE FROM admin_user');
        $admin = (new AdminUser())->setEmail('upgrade@shop.test')->setPassword('unused')->setRoles([AdminUser::ROLE_SUPER_ADMIN])->setIsActive(true);
        $this->em->persist($admin);
        $this->em->flush();
        $client = new KernelBrowser(self::$kernel ?? throw new \LogicException('no kernel'));
        $client->disableReboot();
        $client->loginUser($admin, 'main');

        self::assertSame('1.0.0', $this->liveClass());
        $before = $this->formFields($client, $gatewayId);
        self::assertContains('form[send_chat_id]', $before);
        self::assertNotContains('form[prefer_website_link]', $before);

        $zip = $this->buildZip();
        [$status, $output] = $this->console('app:plugin:upgrade', ['zipPath' => $zip, '--dry-run' => true]);
        self::assertSame(Command::SUCCESS, $status, $output);
        self::assertStringContainsString('Configfieldsadded:prefer_website_link,use_mini_app_link', $output);
        self::assertStringContainsString('Permissionsadded:none', $output);
        self::assertSame('1.0.0', $this->liveClass(), 'a dry run changes nothing');

        [$status, $output] = $this->console('app:plugin:upgrade', ['zipPath' => $zip]);
        self::assertSame(Command::SUCCESS, $status, $output);
        self::assertStringContainsString('Plugintetraminatorupgradedfrom1.0.0to'.$this->shippedVersion().'(statusenabledkept).', $output);
        self::assertStringNotContainsString('FAKE-tetraminator-key', $output);

        self::assertSame('new', $this->liveClass(), 'the new class is live');
        $this->em->clear();
        $plugin = $this->em->getRepository(Plugin::class)->findOneBy(['code' => self::CODE]);
        self::assertInstanceOf(Plugin::class, $plugin);
        self::assertSame([$this->shippedVersion(), Plugin::STATUS_ENABLED], [$plugin->getVersion(), $plugin->getStatus()]);
        self::assertSame($configBefore, $this->em->getConnection()->fetchOne('SELECT config FROM payment_gateway WHERE id = ?', [$gatewayId]));

        $after = $this->formFields($client, $gatewayId);
        self::assertContains('form[prefer_website_link]', $after);
        self::assertContains('form[use_mini_app_link]', $after);
        self::assertContains('form[api_key]', $after);

        [$status, $output] = $this->console('app:plugin:doctor', ['code' => self::CODE]);
        self::assertSame(Command::SUCCESS, $status, $output);
        self::assertStringContainsString('Pluginisusable.', $output);
    }
}
