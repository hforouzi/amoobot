<?php

declare(strict_types=1);

namespace App\Tests\Payment\Plugin;

use App\Entity\PaymentGateway;
use App\Plugin\PluginFilesLock;
use App\Plugin\PluginUpgrader;
use App\Tests\Support\IntegrationTestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Process\Process;

/**
 * app:plugin:upgrade (PluginUpgrader) against fixture plugins built here: each test installs its own plugin under a
 * random code and namespace in var/plugins of this checkout, and removes it (and its backups) again. Output is compared
 * with all whitespace removed, so it holds at any terminal width (COLUMNS).
 */
final class PluginUpgradeTest extends IntegrationTestCase
{
    private const SECRET = 'SECRET-config-value-never-printed-7731';
    private const OLD_VALUE = 'OLD-config-value-4412';

    private string $code;
    private string $namespace;
    private string $projectDir;
    /** @var list<string> */
    private array $zips = [];
    /** @var list<string> */
    private array $cleanup = [];

    protected function setUp(): void
    {
        parent::setUp();
        $suffix = bin2hex(random_bytes(4));
        $this->code = 'upgfix_'.$suffix;
        $this->namespace = 'Amoobot\\Plugin\\Upgfix'.$suffix;
        $this->projectDir = (string) self::getContainer()->getParameter('kernel.project_dir');
    }

    protected function tearDown(): void
    {
        self::getContainer()->get(PluginUpgrader::class)->setAfterSwitchHook(null);
        foreach ($this->zips as $zip) {
            @unlink($zip);
        }
        $paths = array_merge($this->cleanup, [$this->liveDir()], glob($this->projectDir.'/var/plugins/'.PluginUpgrader::BACKUP_DIR.'/'.$this->code.'-*') ?: []);
        foreach ($paths as $path) {
            if (is_link($path) || file_exists($path)) {
                (new Process(['rm', '-rf', $path]))->mustRun();
            }
        }
        if (isset($this->em) && $this->em->isOpen()) {
            $this->em->getConnection()->executeStatement('DELETE FROM plugin WHERE code = ?', [$this->code]);
        }
        parent::tearDown();
    }

    // ---- fixtures -------------------------------------------------------------------------------

    /**
     * @param array<string, mixed> $manifest overrides
     */
    private function zip(string $version, array $manifest = [], string $variant = 'ok', string $class = 'FixturePlugin'): string
    {
        $manifest = array_replace([
            'manifestVersion' => 1,
            'type' => 'payment_gateway',
            'code' => $this->code,
            'name' => ['en' => 'Upgrade fixture '.$version],
            'version' => $version,
            'description' => ['en' => 'Fixture '.$version],
            'mainClass' => $this->namespace.'\\'.$class,
            'category' => 'payment',
            'permissions' => ['payment.create', 'payment.verify'],
            'supportsWebhook' => false,
            'supportsOnlinePayment' => true,
            'configSchema' => [
                ['key' => 'api_key', 'type' => 'password', 'required' => true],
                ['key' => 'old_key', 'type' => 'text', 'required' => false],
            ],
        ], $manifest);

        $path = sys_get_temp_dir().'/'.$this->code.'-'.$version.'-'.bin2hex(random_bytes(3)).'.zip';
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE));
        $zip->addFromString('plugin.json', (string) json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $zip->addFromString('src/'.$class.'.php', $this->source($class, $version, $variant));
        $zip->close();
        $this->zips[] = $path;

        return $path;
    }

    private function source(string $class, string $version, string $variant): string
    {
        $implements = 'no_interface' === $variant ? '' : ' implements PaymentGatewayPluginInterface';
        $broken = 'syntax' === $variant ? 'this is not php' : '';

        return <<<PHP
            <?php

            declare(strict_types=1);

            namespace {$this->namespace};

            use App\\Entity\\Order;
            use App\\Entity\\Payment;
            use App\\Payment\\Domain\\Dto\\PaymentRequestResult;
            use App\\Payment\\Domain\\Dto\\PaymentVerificationResult;
            use App\\Payment\\Plugin\\PaymentGatewayPluginInterface;
            use App\\Payment\\Plugin\\PaymentWebhookResult;
            use Symfony\\Component\\HttpFoundation\\Request;

            final class {$class}{$implements}
            {
                public const FIXTURE_VERSION = '{$version}';
                {$broken}

                public function getType(): string { return '{$this->code}'; }
                public function createPayment(Payment \$payment, Order \$order, array \$config): PaymentRequestResult { return new PaymentRequestResult(success: false, message: 'fixture'); }
                public function verifyPayment(Payment \$payment, array \$payload, array \$config): PaymentVerificationResult { return new PaymentVerificationResult(success: false, paid: false); }
                public function supportsWebhook(): bool { return false; }
                public function handleWebhook(array \$payload, Request \$request, array \$config): ?PaymentWebhookResult { return null; }
            }
            PHP;
    }

    /** Installs 1.0.0 (enabled unless told otherwise) and one gateway using it, holding a secret. */
    private function installV1(bool $enable = true, array $gatewayConfig = ['api_key' => self::SECRET, 'old_key' => self::OLD_VALUE]): PaymentGateway
    {
        [$status, $output] = $this->console('app:plugin:install', ['zipPath' => $this->zip('1.0.0')]);
        self::assertSame(Command::SUCCESS, $status, $output);
        if ($enable) {
            [$status, $output] = $this->console('app:plugin:enable', ['code' => $this->code]);
            self::assertSame(Command::SUCCESS, $status, $output);
        }
        $gateway = $this->createGateway('plugin', $gatewayConfig, 'IRT')->setPluginCode($this->code);
        $this->em->flush();

        return $gateway;
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

        return [$status, $tester->getDisplay()];
    }

    /**
     * @return array{int, string}
     */
    private function upgrade(string $zip, array $options = []): array
    {
        return $this->console('app:plugin:upgrade', ['zipPath' => $zip] + $options);
    }

    private static function assertOutputContains(string $expected, string $output): void
    {
        self::assertStringContainsString((string) preg_replace('/\s+/', '', $expected), (string) preg_replace('/\s+/', '', $output), $output);
    }

    private function liveDir(): string
    {
        return $this->projectDir.'/var/plugins/'.$this->code;
    }

    /** @return array<string, mixed> */
    private function pluginRow(): array
    {
        return $this->em->getConnection()->fetchAssociative('SELECT * FROM plugin WHERE code = ?', [$this->code]) ?: [];
    }

    /** @return array<string, mixed> */
    private function gatewayRow(PaymentGateway $gateway): array
    {
        return $this->em->getConnection()->fetchAssociative('SELECT * FROM payment_gateway WHERE id = ?', [$gateway->getId()]) ?: [];
    }

    /** Every file under the live directory with its content hash. */
    private function filesHash(): string
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->liveDir(), \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            $files[substr($file->getPathname(), strlen($this->liveDir()))] = hash_file('sha256', $file->getPathname());
        }
        ksort($files);

        return hash('sha256', (string) json_encode($files));
    }

    /** @return list<string> */
    private function backups(): array
    {
        return glob($this->projectDir.'/var/plugins/'.PluginUpgrader::BACKUP_DIR.'/'.$this->code.'-*') ?: [];
    }

    private function auditCount(): int
    {
        return (int) $this->em->getConnection()->fetchOne("SELECT COUNT(*) FROM admin_audit_log WHERE action = 'plugin.upgrade' AND details LIKE ?", ['%'.$this->code.'%']);
    }

    /** The installed plugin's class as a fresh PHP process loads it from var/plugins: its FIXTURE_VERSION. */
    private function loadedVersion(string $class = 'FixturePlugin'): string
    {
        $script = sprintf(
            'require %s; require %s; echo \\%s\\%s::FIXTURE_VERSION;',
            var_export($this->projectDir.'/vendor/autoload.php', true),
            var_export($this->liveDir().'/src/'.$class.'.php', true),
            $this->namespace,
            $class,
        );
        $process = new Process([PHP_BINARY, '-r', $script]);
        $process->mustRun();

        return $process->getOutput();
    }

    // ---- success --------------------------------------------------------------------------------

    public function testAnUpgradeKeepsStatusDatesAndGatewayConfigAndRecordsWhoRanIt(): void
    {
        $gateway = $this->installV1();
        $before = $this->pluginRow();
        $gatewayBefore = $this->gatewayRow($gateway);
        $zip = $this->zip('1.1.0', [
            'permissions' => ['payment.create'],
            'configSchema' => [
                ['key' => 'api_key', 'type' => 'password', 'required' => true],
                ['key' => 'mode', 'type' => 'text', 'required' => true, 'default' => 'live'],
            ],
            'name' => ['en' => 'Upgrade fixture renamed'],
        ]);

        [$status, $output] = $this->upgrade($zip);

        self::assertSame(Command::SUCCESS, $status, $output);
        self::assertOutputContains('Version: 1.0.0 -> 1.1.0', $output);
        self::assertOutputContains('Permissions removed: payment.verify', $output);
        self::assertOutputContains('Config fields added: mode', $output);
        self::assertOutputContains('Config fields removed: old_key', $output);
        self::assertOutputContains(sprintf('Gateway #%d keeps keys the new schema no longer has: old_key', $gateway->getId()), $output);
        self::assertOutputContains(sprintf('Plugin %s upgraded from 1.0.0 to 1.1.0 (status enabled kept).', $this->code), $output);
        self::assertOutputContains(sprintf('sudo systemctl reload php%d.%d-fpm', PHP_MAJOR_VERSION, PHP_MINOR_VERSION), $output);
        self::assertOutputContains('app:plugin:doctor '.$this->code, $output);
        self::assertStringNotContainsString(self::SECRET, $output);
        self::assertStringNotContainsString(self::OLD_VALUE, $output);

        $after = $this->pluginRow();
        self::assertSame('1.1.0', $after['version']);
        foreach (['status', 'enabled_at', 'installed_at', 'created_at', 'disabled_at', 'path', 'id'] as $kept) {
            self::assertSame($before[$kept], $after[$kept], $kept);
        }
        self::assertSame('enabled', $after['status']);
        self::assertStringContainsString('renamed', (string) $after['name']);
        self::assertSame(['payment.create'], json_decode((string) $after['permissions'], true));
        self::assertStringContainsString('"mode"', (string) $after['manifest']);
        self::assertSame($gatewayBefore, $this->gatewayRow($gateway), 'the gateway row, config included, byte for byte');

        self::assertSame('1.1.0', $this->loadedVersion(), 'the new files are live');
        self::assertCount(1, $this->backups());
        self::assertFileExists($this->backups()[0].'/plugin.json');
        self::assertStringContainsString('"1.0.0"', (string) file_get_contents($this->backups()[0].'/plugin.json'));

        $audit = $this->em->getConnection()->fetchAssociative("SELECT * FROM admin_audit_log WHERE action = 'plugin.upgrade' AND target_id = ?", [(string) $after['id']]) ?: [];
        self::assertSame('cli', $audit['source'] ?? null);
        self::assertStringStartsWith('cli:', (string) $audit['actor_label']);
        $details = json_decode((string) $audit['details'], true);
        self::assertSame(['code' => $this->code, 'from' => '1.0.0', 'to' => '1.1.0', 'by' => $audit['actor_label']], $details);
        self::assertStringNotContainsString(self::SECRET, (string) json_encode($audit));
    }

    public function testADisabledPluginStaysDisabledAndOnlyTheLatestBackupIsKept(): void
    {
        $this->installV1(enable: false);
        [$status, $output] = $this->console('app:plugin:disable', ['code' => $this->code]);
        self::assertSame(Command::SUCCESS, $status, $output);
        $before = $this->pluginRow();

        [$status, $output] = $this->upgrade($this->zip('1.1.0'));
        self::assertSame(Command::SUCCESS, $status, $output);
        [$status, $output] = $this->upgrade($this->zip('1.2.0-beta.1'));
        self::assertSame(Command::SUCCESS, $status, $output);

        $after = $this->pluginRow();
        self::assertSame(['disabled', '1.2.0-beta.1'], [$after['status'], $after['version']]);
        self::assertSame([$before['enabled_at'], $before['disabled_at']], [$after['enabled_at'], $after['disabled_at']]);
        $backups = $this->backups();
        self::assertCount(1, $backups, 'the older backup is deleted');
        self::assertStringContainsString($this->code.'-1.1.0-', basename($backups[0]));
    }

    public function testTheMainClassMayChangeWhenTheNewOneLoads(): void
    {
        $this->installV1();

        [$status, $output] = $this->upgrade($this->zip('2.0.0', [], 'ok', 'RenamedPlugin'));

        self::assertSame(Command::SUCCESS, $status, $output);
        self::assertOutputContains('Main class:', $output);
        self::assertSame($this->namespace.'\\RenamedPlugin', $this->pluginRow()['main_class']);
        self::assertSame('2.0.0', $this->loadedVersion('RenamedPlugin'));
    }

    public function testANewRequiredFieldWithADefaultOrPresentInTheConfigIsFine(): void
    {
        $this->installV1(gatewayConfig: ['api_key' => self::SECRET, 'merchant' => 'M-1']);

        [$status, $output] = $this->upgrade($this->zip('1.1.0', ['configSchema' => [
            ['key' => 'api_key', 'type' => 'password', 'required' => true],
            ['key' => 'merchant', 'type' => 'text', 'required' => true],
            ['key' => 'mode', 'type' => 'text', 'required' => true, 'default' => 'live'],
        ]]));

        self::assertSame(Command::SUCCESS, $status, $output);
    }

    // ---- refusals -------------------------------------------------------------------------------

    /**
     * @return iterable<string, array{string}>
     */
    public static function notGreaterVersions(): iterable
    {
        yield 'equal' => ['1.0.0'];
        yield 'lower' => ['0.9.9'];
        yield 'a pre-release of the same version' => ['1.0.0-rc.1'];
        yield 'equal with build metadata' => ['1.0.0+build.7'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('notGreaterVersions')]
    public function testAnEqualOrLowerVersionIsRefused(string $version): void
    {
        $this->installV1();
        $before = [$this->pluginRow(), $this->filesHash()];

        [$status, $output] = $this->upgrade($this->zip($version));

        self::assertSame(Command::FAILURE, $status);
        self::assertOutputContains(sprintf('The ZIP version %s is not greater than the installed version 1.0.0.', $version), $output);
        self::assertSame($before, [$this->pluginRow(), $this->filesHash()]);
    }

    public function testANonSemanticVersionIsRefused(): void
    {
        $this->installV1();

        [$status, $output] = $this->upgrade($this->zip('1.1'));

        self::assertSame(Command::FAILURE, $status);
        self::assertOutputContains('must be semantic versions', $output);
    }

    public function testAPluginThatIsNotInstalledIsRefused(): void
    {
        [$status, $output] = $this->upgrade($this->zip('1.1.0'));

        self::assertSame(Command::FAILURE, $status);
        self::assertOutputContains(sprintf('Plugin "%s" is not installed; use app:plugin:install.', $this->code), $output);
        self::assertFileDoesNotExist($this->liveDir());
        self::assertSame([], $this->pluginRow());
    }

    public function testATypeChangeIsRefused(): void
    {
        $this->installV1();
        // Only one type exists today, so the installed row plays an older type.
        $this->em->getConnection()->executeStatement("UPDATE plugin SET type = 'legacy_type' WHERE code = ?", [$this->code]);
        $this->em->clear();
        $files = $this->filesHash();

        [$status, $output] = $this->upgrade($this->zip('1.1.0'));

        self::assertSame(Command::FAILURE, $status);
        self::assertOutputContains('The plugin type cannot change (installed "legacy_type", ZIP "payment_gateway").', $output);
        self::assertSame('1.0.0', $this->pluginRow()['version']);
        self::assertSame($files, $this->filesHash());
    }

    public function testAnAddedPermissionNeedsTheFlag(): void
    {
        $this->installV1();
        $zip = $this->zip('1.1.0', ['permissions' => ['payment.create', 'payment.verify', 'http.outbound', 'webhook.receive']]);

        [$status, $output] = $this->upgrade($zip);

        self::assertSame(Command::FAILURE, $status);
        self::assertOutputContains('Permissions added: http.outbound, webhook.receive', $output);
        self::assertOutputContains('asks for permissions the installed one does not have: http.outbound, webhook.receive', $output);
        self::assertOutputContains('--accept-new-permissions', $output);
        self::assertSame('1.0.0', $this->pluginRow()['version']);

        [$status, $output] = $this->upgrade($zip, ['--accept-new-permissions' => true]);

        self::assertSame(Command::SUCCESS, $status, $output);
        self::assertSame(['payment.create', 'payment.verify', 'http.outbound', 'webhook.receive'], json_decode((string) $this->pluginRow()['permissions'], true));
    }

    public function testANewRequiredFieldMissingFromAGatewayIsRefusedWithItsIdAndNoValue(): void
    {
        $gateway = $this->installV1();
        $other = $this->createGateway('plugin', ['api_key' => self::SECRET.'-2', 'merchant' => 'M-OK'], 'IRT')->setPluginCode($this->code);
        $this->em->flush();
        $before = [$this->pluginRow(), $this->filesHash(), $this->gatewayRow($gateway)];

        [$status, $output] = $this->upgrade($this->zip('1.1.0', ['configSchema' => [
            ['key' => 'api_key', 'type' => 'password', 'required' => true],
            ['key' => 'merchant', 'type' => 'text', 'required' => true],
        ]]));

        self::assertSame(Command::FAILURE, $status);
        self::assertOutputContains(sprintf('Payment gateway #%d lacks the newly required field(s) merchant, which have no default.', $gateway->getId()), $output);
        self::assertOutputContains(sprintf('Gateways using it: #%d, #%d', $gateway->getId(), $other->getId()), $output);
        self::assertStringNotContainsString(sprintf('Payment gateway #%d lacks', $other->getId()), (string) preg_replace('/\s+/', ' ', $output));
        self::assertStringNotContainsString(self::SECRET, $output);
        self::assertStringNotContainsString('M-OK', $output);
        self::assertStringNotContainsString(self::OLD_VALUE, $output);
        self::assertSame($before, [$this->pluginRow(), $this->filesHash(), $this->gatewayRow($gateway)]);
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function brokenNewFiles(): iterable
    {
        yield 'no interface' => ['no_interface', 'FixturePlugin', 'interface_not_implemented'];
        yield 'syntax error' => ['syntax', 'FixturePlugin', 'syntax error'];
        yield 'a renamed class that is missing' => ['ok', 'Missing', 'class_not_found'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('brokenNewFiles')]
    public function testADoctorFailureOnTheNewFilesLeavesTheInstallUntouched(string $variant, string $class, string $error): void
    {
        $gateway = $this->installV1();
        $before = [$this->pluginRow(), $this->filesHash(), $this->gatewayRow($gateway)];
        $zip = $this->zip('1.1.0', [], $variant, 'Missing' === $class ? 'FixturePlugin' : $class);
        if ('Missing' === $class) {
            // The manifest names a class the ZIP does not contain.
            $archive = new \ZipArchive();
            $archive->open($zip);
            $manifest = json_decode((string) $archive->getFromName('plugin.json'), true);
            $manifest['mainClass'] = $this->namespace.'\\Missing';
            $archive->addFromString('plugin.json', (string) json_encode($manifest));
            $archive->close();
        }

        [$status, $output] = $this->upgrade($zip);

        self::assertSame(Command::FAILURE, $status);
        self::assertOutputContains('New files:', $output);
        self::assertStringContainsStringIgnoringCase(str_replace(' ', '', $error), (string) preg_replace('/\s+/', '', $output));
        self::assertSame($before, [$this->pluginRow(), $this->filesHash(), $this->gatewayRow($gateway)]);
        self::assertSame([], $this->backups());
        self::assertSame(0, $this->auditCount());
    }

    public function testAFailureAfterTheSwitchRestoresFilesAndRow(): void
    {
        $gateway = $this->installV1();
        $before = [$this->pluginRow(), $this->filesHash(), $this->gatewayRow($gateway)];
        $upgrader = self::getContainer()->get(PluginUpgrader::class);
        $seen = null;
        $upgrader->setAfterSwitchHook(function () use (&$seen): void {
            $seen = [$this->pluginRow()['version'], $this->loadedVersion()];

            throw new \RuntimeException('simulated failure');
        });

        [$status, $output] = $this->upgrade($this->zip('1.1.0', ['permissions' => ['payment.create']]));

        self::assertSame(['1.1.0', '1.1.0'], $seen, 'the switch had happened: new row, new files');
        self::assertSame(Command::FAILURE, $status);
        self::assertOutputContains('Upgrade failed after the switch', $output);
        self::assertOutputContains('Version 1.0.0 was restored (files and plugin row).', $output);
        $this->em->clear();
        self::assertSame($before, [$this->pluginRow(), $this->filesHash(), $this->gatewayRow($gateway)]);
        self::assertSame('1.0.0', $this->loadedVersion());
        self::assertSame([], $this->backups(), 'the backup went back into place');
        self::assertSame(0, $this->auditCount());
    }

    public function testADryRunChangesNothing(): void
    {
        $gateway = $this->installV1();
        $before = [$this->pluginRow(), $this->filesHash(), $this->gatewayRow($gateway)];

        [$status, $output] = $this->upgrade($this->zip('1.1.0', ['permissions' => ['payment.create', 'payment.verify', 'http.outbound']]), ['--dry-run' => true, '--accept-new-permissions' => true]);

        self::assertSame(Command::SUCCESS, $status, $output);
        self::assertOutputContains(sprintf('Dry run: %s can be upgraded from 1.0.0 to 1.1.0. Nothing was changed.', $this->code), $output);
        self::assertOutputContains('Permissions added: http.outbound', $output);
        self::assertStringNotContainsString('systemctl', $output);
        self::assertSame($before, [$this->pluginRow(), $this->filesHash(), $this->gatewayRow($gateway)]);
        self::assertSame([], $this->backups());
        self::assertSame(0, $this->auditCount());
    }

    public function testADryRunReportsARefusal(): void
    {
        $this->installV1();

        [$status, $output] = $this->upgrade($this->zip('1.1.0', ['permissions' => ['payment.create', 'payment.verify', 'http.outbound']]), ['--dry-run' => true]);

        self::assertSame(Command::FAILURE, $status);
        self::assertOutputContains('Dry run: the upgrade would be refused.', $output);
    }

    public function testAConcurrentUpgradeOrInstallIsRefusedByTheLock(): void
    {
        $this->installV1();
        $lockPath = (new PluginFilesLock($this->projectDir))->path();
        // Another process holds the lock, as a second app:plugin:upgrade would.
        $holder = new Process([PHP_BINARY, '-r', '$h = fopen($argv[1], "c"); flock($h, LOCK_EX); echo "locked\n"; sleep(30);', '--', $lockPath]);
        $holder->start();
        try {
            $holder->waitUntil(static fn (string $type, string $out): bool => str_contains($out, 'locked'));

            [$status, $output] = $this->upgrade($this->zip('1.1.0'));
            self::assertSame(Command::FAILURE, $status);
            self::assertOutputContains(PluginFilesLock::BUSY, $output);
            self::assertSame('1.0.0', $this->pluginRow()['version']);

            [$status, $output] = $this->console('app:plugin:install', ['zipPath' => $this->zip('3.0.0', ['code' => $this->code.'_other'])]);
            self::assertSame(Command::FAILURE, $status);
            self::assertOutputContains(PluginFilesLock::BUSY, $output);
            self::assertFileDoesNotExist($this->liveDir().'_other');
        } finally {
            $holder->stop(0);
        }

        [$status, $output] = $this->upgrade($this->zip('1.1.0'));
        self::assertSame(Command::SUCCESS, $status, $output);
    }

    public function testASymbolicLinkAsTheInstalledDirectoryIsRefused(): void
    {
        $this->installV1();
        $real = sys_get_temp_dir().'/'.$this->code.'-real';
        $this->cleanup[] = $real;
        self::assertTrue(rename($this->liveDir(), $real));
        self::assertTrue(symlink($real, $this->liveDir()));

        [$status, $output] = $this->upgrade($this->zip('1.1.0'));

        self::assertSame(Command::FAILURE, $status);
        self::assertOutputContains(sprintf('var/plugins/%s is a symbolic link; refusing to follow it.', $this->code), $output);
        self::assertTrue(is_link($this->liveDir()));
        self::assertFileExists($real.'/plugin.json');
        self::assertSame('1.0.0', $this->pluginRow()['version']);
    }

    public function testASymbolicLinkInsideTheInstalledFilesIsRefused(): void
    {
        $this->installV1();
        self::assertTrue(symlink('/etc', $this->liveDir().'/src/elsewhere'));

        [$status, $output] = $this->upgrade($this->zip('1.1.0'));

        self::assertSame(Command::FAILURE, $status);
        self::assertOutputContains('The installed files contain a symbolic link (src/elsewhere); refusing to continue.', $output);
        self::assertTrue(is_link($this->liveDir().'/src/elsewhere'), 'nothing removed through it');
        self::assertSame('1.0.0', $this->pluginRow()['version']);
    }
}
