<?php

declare(strict_types=1);

namespace App\Tests\Payment\Plugin;

use App\Entity\Plugin;
use App\Tests\Support\IntegrationTestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Process\Process;

/**
 * docs/plugins/tetraminator-gateway/build.sh makes a ZIP that passes app:plugin:validate-package, installs, enables and
 * passes app:plugin:doctor. Installed into var/plugins/tetraminator of this checkout for the test only, and removed
 * again; no gateway is configured with it. The plugin's own behaviour: TetraminatorGatewayPluginTest.
 */
final class TetraminatorPackageTest extends IntegrationTestCase
{
    private const CODE = 'tetraminator';

    private string $zip;
    private string $installDir;
    private bool $installedHere = false;

    public static function setUpBeforeClass(): void
    {
        // The classes from docs/, as the plugin's unit test loads them: the doctor's class_exists() finds them, so the
        // copies in the ZIP are never declared a second time in this process. They are the same files.
        foreach (['TetraminatorException', 'TetraminatorApi', 'TetraminatorGatewayPlugin'] as $class) {
            require_once dirname(__DIR__, 3).'/docs/plugins/tetraminator-gateway/src/'.$class.'.php';
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->installDir = self::getContainer()->getParameter('kernel.project_dir').'/var/plugins/'.self::CODE;
        if (file_exists($this->installDir)) {
            self::markTestSkipped($this->installDir.' exists already: not touched by this test.');
        }
        $this->em->getConnection()->executeStatement('DELETE FROM plugin WHERE code = ?', [self::CODE]);
        $this->zip = sys_get_temp_dir().'/amoobot-tetraminator-'.bin2hex(random_bytes(4)).'.zip';
    }

    protected function tearDown(): void
    {
        if (isset($this->zip) && is_file($this->zip)) {
            unlink($this->zip);
        }
        if ($this->installedHere) {
            (new Process(['rm', '-rf', $this->installDir]))->mustRun();
        }
        if (isset($this->em) && $this->em->isOpen()) {
            $this->em->getConnection()->executeStatement('DELETE FROM plugin WHERE code = ?', [self::CODE]);
        }
        parent::tearDown();
    }

    /**
     * @param array<string, string> $input
     *
     * @return array{int, string}
     */
    private function console(string $command, array $input): array
    {
        $application = new Application(self::$kernel ?? throw new \LogicException('no kernel'));
        $tester = new CommandTester($application->find($command));
        $status = $tester->execute($input);

        // SymfonyStyle wraps at the terminal width (COLUMNS): compare with the whitespace folded.
        return [$status, (string) preg_replace('/\s+/', ' ', $tester->getDisplay())];
    }

    public function testTheBuiltZipValidatesInstallsEnablesAndPassesTheDoctor(): void
    {
        $build = new Process(['sh', dirname(__DIR__, 3).'/docs/plugins/tetraminator-gateway/build.sh', $this->zip]);
        $build->mustRun();
        self::assertFileExists($this->zip);

        $zip = new \ZipArchive();
        self::assertTrue($zip->open($this->zip));
        $names = [];
        for ($i = 0; $i < $zip->numFiles; ++$i) {
            $names[] = (string) $zip->getNameIndex($i);
        }
        $zip->close();
        sort($names);
        self::assertSame(['README.md', 'plugin.json', 'src/TetraminatorApi.php', 'src/TetraminatorException.php', 'src/TetraminatorGatewayPlugin.php'], $names, 'no tests, no design');

        [$status, $output] = $this->console('app:plugin:validate-package', ['zipPath' => $this->zip]);
        self::assertSame(Command::SUCCESS, $status, $output);
        self::assertStringContainsString('Plugin package is valid.', $output);
        self::assertStringContainsString('api_key, callback_base_url', $output);

        [$status, $output] = $this->console('app:plugin:install', ['zipPath' => $this->zip]);
        $this->installedHere = is_dir($this->installDir);
        self::assertSame(Command::SUCCESS, $status, $output);
        self::assertFileExists($this->installDir.'/src/TetraminatorGatewayPlugin.php');

        [$status, $output] = $this->console('app:plugin:enable', ['code' => self::CODE]);
        self::assertSame(Command::SUCCESS, $status, $output);
        $this->em->clear();
        $plugin = $this->em->getRepository(Plugin::class)->findOneBy(['code' => self::CODE]);
        self::assertInstanceOf(Plugin::class, $plugin);
        self::assertSame(Plugin::STATUS_ENABLED, $plugin->getStatus());
        self::assertSame(['payment.create', 'payment.verify', 'http.outbound'], $plugin->getPermissions());

        [$status, $output] = $this->console('app:plugin:doctor', ['code' => self::CODE]);
        self::assertSame(Command::SUCCESS, $status, $output);
        self::assertStringContainsString('Plugin is usable.', $output);
    }
}
