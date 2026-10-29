<?php

declare(strict_types=1);

namespace App\Tests\Provisioning;

use App\Entity\VpnInbound;
use App\Entity\VpnPanel;
use App\Entity\VpnService;
use App\Provisioning\Domain\SanaeiIntegrationMode;
use App\Provisioning\Infrastructure\Sanaei3xui\Sanaei3xuiRemoteIdParser;
use App\Tests\Support\Fake3xui\Fake3xuiPanel;
use App\Tests\Support\IntegrationTestCase;
use App\Tests\Support\SubprocessAssertions;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * A regenerate of a modern 3x-ui service racing a credential reset ("reset my links") on MariaDB, each in its own PHP
 * process (F3): the regenerate reads the panel for the old credentials, the reset commits new ones before the
 * regenerate writes. The links read for the old credentials are never written over the new ones; the panel is read
 * again for the new credentials; no lock is held during the panel call; a reset that holds the row lock only makes the
 * write wait (no deadlock). Covers the "Regenerate config" button (web / Mini App / claim), app:service:regenerate-config
 * and app:inbound:regenerate-service-configs.
 */
final class RegenerateResetRaceTest extends IntegrationTestCase
{
    use SubprocessAssertions;

    private const OLD_UUID = '11111111-2222-4333-8444-555555555555';
    private const NEW_UUID = '99999999-2222-4333-8444-555555555555';
    private const OLD_SUB = 'raceoldsub000001';
    private const NEW_SUB = 'racenewsub000001';

    private VpnInbound $inbound;
    private VpnService $service;
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $panel = (new VpnPanel())->setType('sanaei_3xui')->setTitle('modern')->setBaseUrl(Fake3xuiPanel::ORIGIN.'/secret/')->setPlainApiToken(Fake3xuiPanel::TOKEN)
            ->recordProbe(SanaeiIntegrationMode::MODERN, 'ok', new \DateTimeImmutable());
        $this->inbound = (new VpnInbound())->setPanel($panel)->setRemoteInboundId('1')->setTitle('reality')->setProtocol('vless')
            ->setHost('origin.example.com')->setPort(443)->setNetwork('tcp')->setSecurity('none');
        $this->em->persist($panel);
        $this->em->persist($this->inbound);
        $this->em->flush();
        $old = 'vless://'.self::OLD_UUID.'@origin.example.com:443#old';
        $this->service = $this->createActiveService($this->createUser('7711'), $this->inbound)
            ->setRemoteId((new Sanaei3xuiRemoteIdParser())->format($panel->getId(), $this->inbound->getId(), '1', self::OLD_UUID, 'tg_race_1', self::OLD_SUB))
            ->setClientUuid(self::OLD_UUID)->setClientEmail('tg_race_1')->setSubId(self::OLD_SUB)
            ->setConfigLinks([$old])->setConfigText($old);
        $this->em->flush();
        $this->dir = sys_get_temp_dir().'/amoobot-race-'.bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir.'/*') ?: [] as $file) {
            unlink($file);
        }
        @rmdir($this->dir);
        parent::tearDown();
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function paths(): iterable
    {
        yield 'Regenerate config button (web, Mini App, claim)' => ['button'];
        yield 'app:service:regenerate-config --apply' => ['single'];
        yield 'app:inbound:regenerate-service-configs --apply' => ['bulk'];
    }

    #[DataProvider('paths')]
    public function testAResetCommittedBetweenThePanelReadAndTheWriteWins(string $mode): void
    {
        $regenerate = $this->startRegenerate($mode);
        $this->waitFor($this->dir.'/ready', $regenerate);

        $reset = $this->process('reset-credentials.php', [(string) $this->service->getId(), self::NEW_UUID, self::NEW_SUB, '0', $this->dir.'/locked']);
        $reset->run();
        self::assertSame('reset', self::assertSubprocessClean($reset, 'reset-credentials.php'));
        touch($this->dir.'/go');
        $regenerate->wait();
        $out = self::assertSubprocessClean($regenerate, 'regenerate-links.php');

        $this->assertTheNewCredentialsKeptTheirLinks($mode, $out);
    }

    #[DataProvider('paths')]
    public function testAResetHoldingTheRowLockOnlyMakesTheWriteWait(string $mode): void
    {
        $regenerate = $this->startRegenerate($mode);
        $this->waitFor($this->dir.'/ready', $regenerate);
        $reset = $this->process('reset-credentials.php', [(string) $this->service->getId(), self::NEW_UUID, self::NEW_SUB, '1500', $this->dir.'/locked']);
        $reset->start();
        $this->waitFor($this->dir.'/locked', $reset);

        // The regenerate now wants the row the reset holds: it waits for the commit, then sees the new credentials.
        touch($this->dir.'/go');
        $regenerate->wait();
        $reset->wait();

        self::assertSame('reset', self::assertSubprocessClean($reset, 'reset-credentials.php'));
        $out = self::assertSubprocessClean($regenerate, 'regenerate-links.php');
        self::assertStringNotContainsStringIgnoringCase('deadlock', $out);
        $this->assertTheNewCredentialsKeptTheirLinks($mode, $out);
    }

    public function testWithoutAResetThePanelsLinksAreWritten(): void
    {
        $regenerate = $this->startRegenerate('button');
        $this->waitFor($this->dir.'/ready', $regenerate);
        touch($this->dir.'/go');
        $regenerate->wait();

        self::assertSame('refreshed_from_panel', self::assertSubprocessClean($regenerate, 'regenerate-links.php'));
        $row = $this->row('vpn_service', (int) $this->service->getId());
        self::assertSame(self::OLD_UUID, $row['client_uuid']);
        self::assertCount(2, json_decode((string) $row['config_links'], true));
        self::assertStringContainsString(self::OLD_UUID.'@cdn2.example.com', (string) $row['config_links']);
    }

    private function assertTheNewCredentialsKeptTheirLinks(string $mode, string $out): void
    {
        $row = $this->row('vpn_service', (int) $this->service->getId());
        self::assertSame(self::NEW_UUID, $row['client_uuid']);
        self::assertSame(self::NEW_SUB, $row['sub_id']);
        self::assertStringNotContainsString(self::OLD_UUID, (string) $row['config_links'], 'links of the old credentials were written over the reset');
        self::assertStringNotContainsString(self::OLD_UUID, (string) $row['config_text']);
        self::assertSame([
            'vless://'.self::NEW_UUID.'@cdn1.example.com:443?type=tcp&security=reality#cdn1.example.com',
            'vless://'.self::NEW_UUID.'@cdn2.example.com:443?type=tcp&security=reality#cdn2.example.com',
        ], json_decode((string) $row['config_links'], true), 'the panel was read again for the new credentials');
        self::assertSame("row-free no-tx\n", (string) file_get_contents($this->dir.'/probe'), 'no row lock and no transaction during the panel call');
        if ('button' === $mode) {
            self::assertSame('refreshed_from_panel', $out);
        } else {
            self::assertStringStartsWith('exit=0', $out);
            self::assertStringContainsString('credentials changed meanwhile', $out);
        }
    }

    private function startRegenerate(string $mode): Process
    {
        $process = $this->process('regenerate-links.php', [$mode, (string) $this->service->getId(), (string) $this->inbound->getId(), self::NEW_UUID, self::NEW_SUB, $this->dir.'/ready', $this->dir.'/go', $this->dir.'/probe']);
        $process->start();

        return $process;
    }

    /**
     * @param list<string> $arguments
     */
    private function process(string $script, array $arguments): Process
    {
        return new Process([(new PhpExecutableFinder())->find(false) ?: PHP_BINARY, dirname(__DIR__).'/Support/bin/'.$script, ...$arguments], dirname(__DIR__, 2), ['APP_ENV' => 'test'], null, 120);
    }

    private function waitFor(string $file, Process $process): void
    {
        for ($i = 0; $i < 1200 && !is_file($file); ++$i) {
            if (!$process->isRunning()) {
                self::fail(sprintf('%s ended before %s: %s %s', basename((string) $process->getCommandLine()), basename($file), $process->getOutput(), $process->getErrorOutput()));
            }
            usleep(25_000);
        }
        self::assertFileExists($file);
    }
}
