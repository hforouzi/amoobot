<?php

declare(strict_types=1);

namespace App\Tests\Module;

use App\Entity\Plugin;
use App\Module\Application\ModuleAllowList;
use App\Module\Application\ProviderDriverRegistry;
use App\Payment\Plugin\PluginConfigSchemaValidator;
use App\Plugin\PaymentPluginDoctor;
use App\Plugin\PluginFilesLock;
use App\Plugin\PluginManager;
use App\Provisioning\Domain\VpnDriverInterface;
use App\Tests\Support\IntegrationTestCase;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * PM2b (docs/design/product-modules.md 4.2-4.5): the fixture tests/Support/plugins/fake-vpn-driver as a ZIP, through the
 * same commands an operator runs. It installs, enables (then ProviderDriverRegistry has it, as a package), passes the
 * doctor, shows in app:product:list-modules and disables again. A wrong hash, contract or core version, or a driver
 * without panel_sub_links/subscription_url, installs as 'error' and is never loaded. Installed into
 * var/plugins/fake_vpn of this checkout for the test only, and removed again.
 */
final class DriverPackageTest extends IntegrationTestCase
{
    private const CODE = 'fake_vpn';
    private const FIXTURE = __DIR__.'/../Support/plugins/fake-vpn-driver';

    private string $installDir;
    /** @var list<string> */
    private array $zips = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->installDir = self::getContainer()->getParameter('kernel.project_dir').'/var/plugins/'.self::CODE;
        $this->cleanUp();
        self::seedCoreRows($this->em->getConnection());
    }

    protected function tearDown(): void
    {
        foreach ($this->zips as $zip) {
            if (is_file($zip)) {
                unlink($zip);
            }
        }
        if (isset($this->em) && $this->em->isOpen()) {
            $this->cleanUp();
        }
        parent::tearDown();
    }

    public function testTheFixtureInstallsEnablesLoadsAndDisables(): void
    {
        [$status, $output] = $this->console('app:plugin:validate-package', ['zipPath' => $this->zip()]);
        self::assertSame(Command::SUCCESS, $status, $output);

        [$status, $output] = $this->console('app:plugin:install', ['zipPath' => $this->zip()]);
        self::assertSame(Command::SUCCESS, $status, $output);
        $plugin = $this->plugin();
        self::assertSame(Plugin::STATUS_INSTALLED, $plugin->getStatus());
        self::assertSame(Plugin::TYPE_VPN_DRIVER, $plugin->getType());
        self::assertSame(Plugin::SOURCE_PACKAGE, $plugin->getSource());
        self::assertFalse($this->registry()->has('vpn', self::CODE), 'installed is not enabled');

        [$status, $output] = $this->console('app:plugin:enable', ['code' => self::CODE]);
        self::assertSame(Command::SUCCESS, $status, $output);
        self::assertSame(Plugin::STATUS_ENABLED, $this->plugin()->getStatus());

        $registry = $this->registry();
        self::assertTrue($registry->has('vpn', self::CODE));
        self::assertTrue($registry->isPackage('vpn', self::CODE));
        self::assertFalse($registry->isPackage('vpn', 'dummy'), 'core drivers stay core');
        $driver = $registry->get('vpn', self::CODE);
        self::assertInstanceOf(VpnDriverInterface::class, $driver);
        self::assertSame(['panel_sub_links', 'traffic_reset'], $driver->capabilities()->names());
        self::assertSame(['dummy', 'fake_vpn', 'sanaei_3xui', 'sanaei_3xui_legacy'], $registry->codes('vpn'));

        [$status, $output] = $this->console('app:plugin:doctor', ['code' => self::CODE]);
        self::assertSame(Command::SUCCESS, $status, $output);
        self::assertStringContainsString('Plugin is usable.', $output);
        self::assertStringContainsString('file hashes match yes', $output);

        [, $output] = $this->console('app:product:list-modules', []);
        self::assertMatchesRegularExpression('/driver vpn fake_vpn package 1\.0\.0 1 enabled yes panel_sub_links, traffic_reset/', $output);

        [$status, $output] = $this->console('app:plugin:disable', ['code' => self::CODE]);
        self::assertSame(Command::SUCCESS, $status, $output);
        self::assertFalse($this->registry()->has('vpn', self::CODE));
        [, $output] = $this->console('app:product:list-modules', []);
        self::assertMatchesRegularExpression('/driver vpn fake_vpn package 1\.0\.0 1 disabled yes panel_sub_links, traffic_reset \(manifest\)/', $output);
    }

    /**
     * @return iterable<string, array{\Closure(array<string, mixed>): array<string, mixed>, array<string, string>, string}>
     */
    public static function refusedPackages(): iterable
    {
        yield 'wrong hash' => [static fn (array $m): array => $m, ['src/FakeVpnDriver.php' => "<?php\n// changed after the build\n"], 'integrity: src/FakeVpnDriver.php does not match its hash.'];
        yield 'file not listed' => [static fn (array $m): array => $m, ['src/Extra.php' => "<?php\n"], 'integrity: src/Extra.php is not listed in the manifest.'];
        yield 'wrong contract' => [static function (array $m): array {
            $m['requires']['contract']['vpn_driver'] = VpnDriverInterface::VERSION + 1;

            return $m;
        }, [], 'contract: the package is built for vpn_driver contract '.(VpnDriverInterface::VERSION + 1)];
        yield 'wrong core version' => [static function (array $m): array {
            $m['requires']['core'] = '>=99.0';

            return $m;
        }, [], 'core_version: the package requires core ">=99.0"'];
        yield 'no panel_sub_links or subscription_url' => [static function (array $m): array {
            $m['mainClass'] = 'Amoobot\\Plugin\\FakeVpnDriver\\NoLinksVpnDriver';

            return $m;
        }, [], 'capabilities: a vpn driver package must declare one of panel_sub_links, subscription_url.'];
    }

    /**
     * @param \Closure(array<string, mixed>): array<string, mixed> $manifest
     * @param array<string, string>                                $files
     */
    #[DataProvider('refusedPackages')]
    public function testARefusedPackageIsInstalledAsErrorAndNeverLoaded(\Closure $manifest, array $files, string $reason): void
    {
        [$status, $output] = $this->console('app:plugin:install', ['zipPath' => $this->zip($manifest, $files)]);

        self::assertSame(Command::FAILURE, $status, $output);
        self::assertStringContainsString('installed with status error, not loaded', $output);
        $plugin = $this->plugin();
        self::assertSame(Plugin::STATUS_ERROR, $plugin->getStatus());
        self::assertStringContainsString($reason, (string) $plugin->getErrorMessage());

        [$status] = $this->console('app:plugin:enable', ['code' => self::CODE]);
        self::assertSame(Command::FAILURE, $status, 'the doctor runs again at enable');
        self::assertSame(Plugin::STATUS_ERROR, $this->plugin()->getStatus());

        // Even with the row forced to enabled, the registry does not load it and sets it back to error.
        $this->em->getConnection()->executeStatement("UPDATE plugin SET status = 'enabled' WHERE code = ?", [self::CODE]);
        self::assertFalse($this->registry()->has('vpn', self::CODE));
        self::assertSame('error', $this->em->getConnection()->fetchOne('SELECT status FROM plugin WHERE code = ?', [self::CODE]));

        [$status, $output] = $this->console('app:plugin:doctor', ['code' => self::CODE]);
        self::assertSame(Command::FAILURE, $status);
        self::assertStringContainsString('Plugin is not usable.', $output);
    }

    public function testAFileChangedAfterEnableIsCaughtWhenTheRegistryLoads(): void
    {
        [$status, $output] = $this->console('app:plugin:install', ['zipPath' => $this->zip(), '--enable' => true]);
        self::assertSame(Command::SUCCESS, $status, $output);
        self::assertSame(Plugin::STATUS_ENABLED, $this->plugin()->getStatus());

        file_put_contents($this->installDir.'/src/NoLinksVpnDriver.php', "\n", FILE_APPEND);

        self::assertFalse($this->registry()->has('vpn', self::CODE));
        $this->em->clear();
        $plugin = $this->plugin();
        self::assertSame(Plugin::STATUS_ERROR, $plugin->getStatus());
        self::assertStringContainsString('integrity: src/NoLinksVpnDriver.php does not match its hash.', (string) $plugin->getErrorMessage());
    }

    public function testADriverPackageCannotBeUploadedFromTheWebWhilePluginUploadIsOff(): void
    {
        $result = $this->manager()->installFromZip($this->zip(), fromWeb: true);

        self::assertFalse($result->success);
        self::assertStringContainsString('PLUGIN_UPLOAD_ENABLED is off', (string) $result->error);
        self::assertNull($this->em->getRepository(Plugin::class)->findOneBy(['code' => self::CODE]));
        self::assertDirectoryDoesNotExist($this->installDir);
    }

    public function testCoreCodesAndTheModuleTypeCannotBeInstalled(): void
    {
        foreach (['dummy', 'sanaei_3xui', 'vpn', 'server'] as $code) {
            $result = $this->manager()->installFromZip($this->zip(static function (array $m) use ($code): array {
                $m['code'] = $code;

                return $m;
            }));
            self::assertFalse($result->success, $code);
            self::assertStringContainsString(sprintf('code "%s" is reserved.', $code), (string) $result->error);
        }

        $result = $this->manager()->installFromZip($this->zip(static function (array $m): array {
            $m['type'] = Plugin::TYPE_PRODUCT_MODULE;

            return $m;
        }));
        self::assertFalse($result->success);
        self::assertStringContainsString('type is not supported.', (string) $result->error);
    }

    public function testAMalformedVersion2ManifestIsRefusedAtInstall(): void
    {
        $result = $this->manager()->installFromZip($this->zip(static function (array $m): array {
            $m['manifestVersion'] = 1;
            unset($m['requires'], $m['files']);

            return $m;
        }));

        self::assertFalse($result->success);
        self::assertStringContainsString('manifestVersion must be 2 for a driver package.', (string) $result->error);
        self::assertStringContainsString('requires is required', (string) $result->error);
        self::assertStringContainsString('files must map every file', (string) $result->error);
        self::assertDirectoryDoesNotExist($this->installDir);
    }

    public function testAppModulesAllowedKeepsTheServerModuleFromBeingEnabled(): void
    {
        [$status, $output] = $this->console('app:plugin:enable', ['code' => 'server']);

        self::assertSame(Command::FAILURE, $status);
        self::assertStringContainsString('Module "server" is not allowed on this install (APP_MODULES_ALLOWED=vpn); server was not enabled.', $output);
        self::assertSame('disabled', $this->em->getConnection()->fetchOne("SELECT status FROM plugin WHERE code = 'server'"));

        // The vpn module and its core drivers can be enabled (they are already).
        [$status, $output] = $this->console('app:plugin:enable', ['code' => 'vpn']);
        self::assertSame(Command::SUCCESS, $status, $output);

        // On an install that allows it (the server install: APP_MODULES_ALLOWED=server), the same row can be enabled.
        $container = self::getContainer();
        $manager = new PluginManager(
            $this->em,
            new NullLogger(),
            $container->get(PaymentPluginDoctor::class),
            $container->get(PluginConfigSchemaValidator::class),
            $container->get(PluginFilesLock::class),
            (string) $container->getParameter('kernel.project_dir'),
            moduleAllowList: new ModuleAllowList('vpn,server'),
        );
        $server = $this->em->getRepository(Plugin::class)->findOneBy(['code' => 'server']) ?? throw new \LogicException();
        $manager->enable($server);
        self::assertSame('enabled', $this->em->getConnection()->fetchOne("SELECT status FROM plugin WHERE code = 'server'"));
    }

    public function testListModulesShowsModulesAndCoreDrivers(): void
    {
        [$status, $output] = $this->console('app:product:list-modules', []);

        self::assertSame(Command::SUCCESS, $status);
        self::assertStringContainsString('kind module code source version contract status allowed capabilities', $output);
        self::assertMatchesRegularExpression('/module server server core - - disabled no -/', $output);
        self::assertMatchesRegularExpression('/module vpn vpn core \d+\.\d+\.\d+ 1 enabled yes -/', $output);
        self::assertMatchesRegularExpression('/driver vpn dummy core \d+\.\d+\.\d+ 1 enabled yes traffic_reset /', $output);
        self::assertMatchesRegularExpression('/driver vpn sanaei_3xui core \d+\.\d+\.\d+ 1 enabled yes adopt_existing, .*panel_sub_links/', $output);
        self::assertMatchesRegularExpression('/driver vpn sanaei_3xui_legacy core \d+\.\d+\.\d+ 1 enabled yes adopt_existing, traffic_add, traffic_reset/', $output);
    }

    /** The core rows as Version20261122120000 inserts them, for a suite in which a test deleted every plugin row. */
    public static function seedCoreRows(Connection $connection): void
    {
        foreach (['vpn' => ['product_module', 'enabled'], 'server' => ['product_module', 'disabled'], 'sanaei_3xui' => ['vpn_driver', 'enabled'], 'dummy' => ['vpn_driver', 'enabled']] as $code => [$type, $status]) {
            $connection->executeStatement(
                "INSERT IGNORE INTO plugin (code, type, name, version, status, source, path, manifest, installed_at, created_at) VALUES (?, ?, ?, 'core', ?, 'core', '', '{}', NOW(), NOW())",
                [$code, $type, json_encode(['en' => $code]), $status],
            );
        }
        $connection->executeStatement("UPDATE plugin SET status = 'disabled' WHERE code = 'server'");
    }

    private function cleanUp(): void
    {
        $this->em->getConnection()->executeStatement('DELETE FROM plugin WHERE code = ?', [self::CODE]);
        $this->manager()->removeDirectory($this->installDir);
        $this->registry()->reset();
        $this->em->clear();
    }

    /**
     * The fixture as a ZIP, with its manifest changed by $manifest and files added or replaced (after the hashes).
     *
     * @param (\Closure(array<string, mixed>): array<string, mixed>)|null $manifest
     * @param array<string, string>                                        $files
     */
    private function zip(?\Closure $manifest = null, array $files = []): string
    {
        $data = json_decode((string) file_get_contents(self::FIXTURE.'/plugin.json'), true, 512, JSON_THROW_ON_ERROR);
        if (null !== $manifest) {
            $data = $manifest($data);
        }
        $path = sys_get_temp_dir().'/amoobot-fake-vpn-'.bin2hex(random_bytes(4)).'.zip';
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($path, \ZipArchive::CREATE));
        $zip->addFromString('plugin.json', (string) json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        foreach (['src/FakeVpnDriver.php', 'src/NoLinksVpnDriver.php'] as $file) {
            $zip->addFromString($file, $files[$file] ?? (string) file_get_contents(self::FIXTURE.'/'.$file));
        }
        foreach ($files as $file => $content) {
            if (!in_array($file, ['src/FakeVpnDriver.php', 'src/NoLinksVpnDriver.php'], true)) {
                $zip->addFromString($file, $content);
            }
        }
        $zip->close();
        $this->zips[] = $path;

        return $path;
    }

    private function plugin(): Plugin
    {
        $this->em->clear();

        return $this->em->getRepository(Plugin::class)->findOneBy(['code' => self::CODE]) ?? throw new \LogicException('not installed');
    }

    private function registry(): ProviderDriverRegistry
    {
        $registry = self::getContainer()->get('test.module.provider_driver_registry');
        self::assertInstanceOf(ProviderDriverRegistry::class, $registry);
        $registry->reset();

        return $registry;
    }

    private function manager(): PluginManager
    {
        $manager = self::getContainer()->get(PluginManager::class);
        self::assertInstanceOf(PluginManager::class, $manager);

        return $manager;
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
        $this->em->clear();

        // SymfonyStyle wraps at the terminal width (COLUMNS) and draws tables: compare with the borders and whitespace folded.
        return [$status, trim((string) preg_replace('/\s+/', ' ', str_replace(['|', '-+-'], ' ', $tester->getDisplay())))];
    }
}
