<?php

declare(strict_types=1);

namespace App\Tests\Shop\InboundMove;

use App\Entity\VpnService;
use App\Shop\Application\InboundMoveSettings;
use App\Tests\Shop\PlanInboundChange\PlanInboundChangeFixtures;
use App\Tests\Support\ConsoleOutputAssertions;
use App\Tests\Support\Fake3xui\Fake3xuiPanel;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The owner's situation of docs/design/plan-inbound-change.md 2 on the fake modern 3x-ui (PlanInboundChangeFixtures):
 * plan "Gold" sold DE-1 (remote 1), its services' clients are on DE-1 only, and the plan now sells NL-2 (remote 2).
 * Plus the command and the reads the P4 tests share. Console text is checked with ConsoleOutputAssertions: a fixed
 * wide terminal (also for the helper processes) and whitespace collapsed, since SymfonyStyle wraps its blocks at the
 * terminal width.
 *
 * @mixin \App\Tests\Support\IntegrationTestCase
 */
trait InboundMoveFixtures
{
    use ConsoleOutputAssertions;
    use PlanInboundChangeFixtures;

    private function setUpMove(bool $switchOn = true): void
    {
        $this->setUpInboundChange();
        $this->em->getConnection()->executeStatement('DELETE FROM admin_audit_log');
        if ($switchOn) {
            $this->setting(InboundMoveSettings::ENABLED, '1', 'boolean');
        }
        // No pause between batches in tests.
        $this->setting(InboundMoveSettings::PAUSE_SECONDS, '0');
    }

    private function setting(string $key, string $value, string $type = 'string'): void
    {
        $db = $this->em->getConnection();
        $db->executeStatement('DELETE FROM setting WHERE key_name = ?', [$key]);
        $db->insert('setting', ['key_name' => $key, 'value' => $value, 'type' => $type, 'created_at' => '2026-01-01 00:00:00']);
    }

    /** A service of Gold on DE-1 with a client on the fake (on DE-1 only), after which Gold sells NL-2. */
    private function movableService(int $n): VpnService
    {
        $service = $this->planService($this->gold, $this->de, n: $n);
        $this->sellOn($this->gold, $this->nl);

        return $service;
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array{int, string} exit code, output
     */
    private function command(array $input): array
    {
        $application = new Application(self::$kernel ?? self::bootKernel());
        $tester = new CommandTester($application->find('app:plan:apply-inbounds'));
        $code = $tester->execute(['planId' => (string) $this->gold->getId()] + $input);
        $this->em->clear();

        return [$code, $tester->getDisplay()];
    }

    /** @return array<string, mixed> */
    private function serviceRow(int $id): array
    {
        return $this->em->getConnection()->fetchAssociative('SELECT * FROM vpn_service WHERE id = ?', [$id]) ?: [];
    }

    /** @return list<array<string, mixed>> */
    private function moves(): array
    {
        return $this->em->getConnection()->fetchAllAssociative('SELECT * FROM service_inbound_move ORDER BY id');
    }

    /** @return list<array<string, mixed>> */
    private function runs(): array
    {
        return $this->em->getConnection()->fetchAllAssociative('SELECT * FROM inbound_move_run ORDER BY id');
    }

    /** @return list<string> */
    private function panelWrites(): array
    {
        return array_values(array_filter($this->fake->requestLines(), static fn (string $l): bool => !str_starts_with($l, 'GET ')));
    }

    /** The body of the fake panel's request number $index (in the request log). */
    private function requestBody(int $index): mixed
    {
        return $this->fake->requests[$index]['body'] ?? null;
    }

    /** @return list<string> */
    private function attachCalls(): array
    {
        return array_values(array_filter($this->fake->requestLines(), static fn (string $l): bool => str_ends_with($l, '/attach')));
    }

    /** @return list<array{action: string, details: array<string, mixed>}> */
    private function audits(): array
    {
        return array_map(static fn (array $r): array => ['action' => (string) $r['action'], 'details' => json_decode((string) $r['details'], true) ?: []],
            $this->em->getConnection()->fetchAllAssociative("SELECT action, details FROM admin_audit_log WHERE action LIKE 'plan.inbounds_apply%' ORDER BY id"));
    }

    /**
     * What a moved client's link would hold: none of it may appear in the output (D5, 6.3).
     *
     * @return list<string>
     */
    private function secretsOf(VpnService $service): array
    {
        return array_values(array_filter([
            (string) $service->getClientUuid(),
            (string) $service->getSubId(),
            (string) $service->getClientEmail(),
            (string) $service->getPublicToken(),
            'vless://',
            Fake3xuiPanel::TOKEN,
        ]));
    }
}
