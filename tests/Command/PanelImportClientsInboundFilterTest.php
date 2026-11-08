<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Entity\VpnInbound;
use App\Entity\VpnPanel;
use App\Provisioning\Domain\SanaeiIntegrationMode;
use App\Tests\Support\ConsoleOutputAssertions;
use App\Tests\Support\Fake3xui\Fake3xuiPanel;
use App\Tests\Support\IntegrationTestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Uid\Uuid;

/**
 * app:panel:import-clients --inbound=ID (docs/design/panel-client-import.md): only clients on a chosen inbound (the
 * panel's inbound number) are imported, each with all its inbounds; the others are counted and nothing more is read for
 * them; an unknown or invalid --inbound is refused before any client is read. Without the option nothing changes
 * (PanelImportClientsCommandTest).
 */
final class PanelImportClientsInboundFilterTest extends IntegrationTestCase
{
    use ConsoleOutputAssertions;

    private Fake3xuiPanel $fake;
    private VpnPanel $panel;
    /** @var array<int, VpnInbound> by panel inbound number */
    private array $inbounds = [];
    /** @var array<string, string> email => subId */
    private array $subIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->fake = new Fake3xuiPanel();
        $this->fake->addInbound(['remark' => 'fourth', 'protocol' => 'vless', 'port' => 2096]);
        $this->http->on('panel.example.test', $this->fake);
        $this->panel = (new VpnPanel())->setType('sanaei_3xui')->setTitle('old panel')->setBaseUrl(Fake3xuiPanel::ORIGIN.'/secret/')->setSubscriptionBaseUrl('https://sub.example.com')
            ->setPlainApiToken(Fake3xuiPanel::TOKEN)->recordProbe(SanaeiIntegrationMode::MODERN, 'ok', new \DateTimeImmutable());
        $this->em->persist($this->panel);
        // Panel inbounds 1, 2 and 4 are synced; 3 is not.
        foreach ([1 => 'vless', 2 => 'vmess', 4 => 'vless'] as $remote => $protocol) {
            $this->inbounds[$remote] = (new VpnInbound())->setPanel($this->panel)->setRemoteInboundId((string) $remote)->setTitle('in-'.$remote)->setProtocol($protocol);
            $this->em->persist($this->inbounds[$remote]);
        }
        $this->em->flush();
        $this->client('on-1-a', '700000001', [1]);
        $this->client('on-1-b', '700000002', [1]);
        $this->client('on-2', '700000003', [2]);
        $this->client('on-1-and-2', '700000004', [2, 1]);
        $this->client('on-4', '700000005', [4]);
    }

    public function testOneChosenInboundImportsOnlyItsClients(): void
    {
        [$code, $out] = $this->import(['--inbound' => ['1']]);

        self::assertSame(0, $code, $out);
        self::assertConsoleContains('Only clients of inbound 1.', $out);
        $this->assertCounts($out, read: 5, imported: 3, notChosen: 2);
        self::assertSame(['on-1-a', 'on-1-and-2', 'on-1-b'], $this->imported());
        $this->assertNothingReadFor(['on-2', 'on-4']);
    }

    public function testTwoChosenInbounds(): void
    {
        [$code, $out] = $this->import(['--inbound' => ['2', '4', '2']]);

        self::assertSame(0, $code, $out);
        self::assertConsoleContains('Only clients of inbound 2, 4.', $out);
        $this->assertCounts($out, read: 5, imported: 3, notChosen: 2);
        self::assertSame(['on-1-and-2', 'on-2', 'on-4'], $this->imported());
        $this->assertNothingReadFor(['on-1-a', 'on-1-b']);
    }

    public function testAClientAlsoOnOtherInboundsKeepsAllOfThem(): void
    {
        [$code, $out] = $this->import(['--inbound' => ['1']]);

        self::assertSame(0, $code, $out);
        $service = $this->em->getConnection()->fetchAssociative("SELECT id, inbound_id, config_links FROM vpn_service WHERE client_email = 'on-1-and-2'");
        self::assertIsArray($service);
        self::assertSame($this->inbounds[2]->getId(), (int) $service['inbound_id'], 'the primary is the panel\'s first inbound, chosen or not');
        self::assertSame([$this->inbounds[1]->getId()], array_map('intval', $this->em->getConnection()->fetchFirstColumn('SELECT inbound_id FROM vpn_service_inbound WHERE service_id = ?', [$service['id']])));
        self::assertCount(2, json_decode((string) $service['config_links'], true), 'links of both inbounds');
    }

    public function testAnUnknownInboundIsRefusedBeforeAnythingIsRead(): void
    {
        foreach ([['3'], ['1', '99', '3']] as $given) {
            [$code, $out] = $this->import(['--inbound' => $given]);

            self::assertSame(Command::FAILURE, $code, $out);
            self::assertConsoleContains(sprintf('Inbound %s is not known for this panel: run app:panel:sync-inbounds %d first. Nothing was read or imported.', implode(', ', array_values(array_diff($given, ['1']))), $this->panel->getId()), $out);
        }
        self::assertSame([], $this->fake->requestLines(), 'no client was read');
        $this->assertNothingWritten();
    }

    public function testAnInvalidInboundIsRefused(): void
    {
        foreach (['abc', '0', '-1', '1.5', ''] as $value) {
            [$code, $out] = $this->import(['--inbound' => ['1', $value]]);

            self::assertSame(Command::INVALID, $code, $value.': '.$out);
            self::assertConsoleContains(sprintf('"%s" is not one. Nothing was read or imported.', $value), $out);
        }
        self::assertSame([], $this->fake->requestLines());
        $this->assertNothingWritten();
    }

    public function testWithoutTheOptionNothingChanges(): void
    {
        [$code, $out] = $this->import();

        self::assertSame(0, $code, $out);
        $this->assertCounts($out, read: 5, imported: 5, notChosen: null);
        self::assertConsoleNotContains('Not on the chosen inbounds', $out);
        self::assertConsoleNotContains('Only clients of inbound', $out);
    }

    public function testADryRunWithAChosenInboundWritesNothing(): void
    {
        [$code, $out] = $this->import(['--inbound' => ['2'], '--dry-run' => true]);

        self::assertSame(0, $code, $out);
        self::assertConsoleContains('Would import: 2', $out);
        self::assertConsoleContains('Not on the chosen inbounds: 3', $out);
        $this->assertNothingWritten();
    }

    public function testARerunImportsNothing(): void
    {
        $this->import(['--inbound' => ['1']]);

        [$code, $out] = $this->import(['--inbound' => ['1']]);

        self::assertSame(0, $code, $out);
        $this->assertCounts($out, read: 5, imported: 0, alreadyKnown: 3, notChosen: 2);
        [, $out] = $this->import(['--inbound' => ['1', '2', '4']]);
        $this->assertCounts($out, read: 5, imported: 2, alreadyKnown: 3, notChosen: 0);
    }

    public function testLimitCountsImportedOnly(): void
    {
        [$code, $out] = $this->import(['--inbound' => ['4', '2'], '--limit' => '1']);

        self::assertSame(0, $code, $out);
        self::assertConsoleContains('Imported: 1', $out);
        self::assertConsoleContains('Stopped at --limit', $out);
    }

    /**
     * Rows that do not name their inbounds (paged and list): the full row decides, and a client filtered out is still
     * never asked for its links.
     */
    public function testWithoutInboundIdsInTheListsTheFullRowDecides(): void
    {
        $rows = fn (bool $paged): array => array_values(array_map(
            static fn (array $c): array => array_diff_key($c, ['inboundIds' => true, 'id' => true, 'flow' => true, 'tgId' => true]) + ($paged ? [] : ['uuid' => $c['id']]),
            $this->fake->clients,
        ));
        $this->fake->script('GET', 'panel/api/clients/list', static fn (): MockResponse => Fake3xuiPanel::envelope(true, $rows(false)));
        $this->fake->script('GET', 'panel/api/clients/list/paged', static fn (): MockResponse => Fake3xuiPanel::envelope(true, ['items' => $rows(true), 'total' => 5]));

        [$code, $out] = $this->import(['--inbound' => ['4']]);

        self::assertSame(0, $code, $out);
        $this->assertCounts($out, read: 5, imported: 1, notChosen: 4);
        self::assertSame(['on-4'], $this->imported());
        $lines = $this->fake->requestLines();
        self::assertCount(5, array_filter($lines, static fn (string $l): bool => str_starts_with($l, 'GET panel/api/clients/get/')), 'one full row per client');
        self::assertSame(['GET panel/api/clients/subLinks/'.$this->subIds['on-4']], array_values(array_filter($lines, static fn (string $l): bool => str_contains($l, 'Links/') || str_contains($l, '/links/'))), 'links of the imported client only');
    }

    public function testTheOutputReadsAtSixtyColumns(): void
    {
        putenv('COLUMNS=60');
        $_SERVER['COLUMNS'] = $_ENV['COLUMNS'] = '60';

        [$code, $out] = $this->import(['--inbound' => ['1', '4']]);
        [$refused, $refusal] = $this->import(['--inbound' => ['3']]);

        self::assertSame(0, $code, $out);
        $this->assertCounts($out, read: 5, imported: 4, notChosen: 1);
        self::assertSame(Command::FAILURE, $refused);
        self::assertConsoleContains('Inbound 3 is not known for this panel: run app:panel:sync-inbounds '.$this->panel->getId().' first.', $refusal);
        foreach (explode("\n", $out."\n".$refusal) as $line) {
            self::assertLessThanOrEqual(60, mb_strlen(rtrim($line)), $line);
        }
    }

    /**
     * @param list<int> $inboundIds
     */
    private function client(string $email, string $tgId, array $inboundIds): void
    {
        $this->subIds[$email] = bin2hex(random_bytes(8));
        $this->fake->clients[$email] = [
            'email' => $email, 'id' => Uuid::v4()->toRfc4122(), 'subId' => $this->subIds[$email], 'flow' => '', 'totalGB' => 0, 'expiryTime' => 0, 'limitIp' => 0,
            'limitHwid' => 0, 'tgId' => (int) $tgId, 'comment' => '', 'enable' => true, 'group' => '', 'reset' => 0, 'inboundIds' => $inboundIds, 'dbId' => count($this->fake->clients) + 1,
        ];
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array{int, string}
     */
    private function import(array $options = []): array
    {
        $this->em->clear();
        $tester = new CommandTester((new Application(self::$kernel))->find('app:panel:import-clients'));
        $code = $tester->execute(['--panel' => (string) $this->panel->getId()] + $options);

        return [$code, $tester->getDisplay()];
    }

    private function assertCounts(string $out, int $read, int $imported, ?int $notChosen, int $alreadyKnown = 0): void
    {
        self::assertConsoleContains('Clients read: '.$read, $out);
        self::assertConsoleContains('Imported: '.$imported, $out);
        self::assertConsoleContains('Already known: '.$alreadyKnown, $out);
        self::assertConsoleContains('No owner (no tgId): 0', $out);
        self::assertConsoleContains('Unknown inbound: 0', $out);
        self::assertConsoleContains('Invalid: 0', $out);
        self::assertConsoleContains('Most services for one owner: '.(0 === $imported ? 0 : 1), $out);
        if (null !== $notChosen) {
            self::assertConsoleContains('Not on the chosen inbounds: '.$notChosen, $out);
        }
    }

    /**
     * @param list<string> $emails
     */
    private function assertNothingReadFor(array $emails): void
    {
        foreach ($emails as $email) {
            foreach ($this->fake->requestLines() as $line) {
                self::assertStringEndsNotWith('/'.$email, $line, 'nothing read for '.$email);
                self::assertStringNotContainsString($this->subIds[$email], $line, 'no links read for '.$email);
            }
        }
    }

    private function assertNothingWritten(): void
    {
        foreach (['vpn_service', 'vpn_service_inbound', 'telegram_account', '`user`', 'service_notification_log'] as $table) {
            self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM '.$table), $table);
        }
    }

    /**
     * @return list<string>
     */
    private function imported(): array
    {
        return array_map('strval', $this->em->getConnection()->fetchFirstColumn('SELECT client_email FROM vpn_service ORDER BY client_email'));
    }
}
