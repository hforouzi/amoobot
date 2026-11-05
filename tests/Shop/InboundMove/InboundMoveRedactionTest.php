<?php

declare(strict_types=1);

namespace App\Tests\Shop\InboundMove;

use App\Entity\Reseller;
use App\Shop\Application\MultiInboundSettings;
use App\Tests\Support\Fake3xui\Fake3xuiPanel;
use App\Tests\Support\IntegrationTestCase;
use App\Tests\Support\RecordingLogger;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * PR P4, D5 and 6.3 of docs/design/plan-inbound-change.md: whatever the panel answers or throws, the command's output,
 * the logs (after RedactingLogger), the stored errors, the audit entries and the admin alerts hold ids and titles only:
 * no owner, link, UUID, subId, client email, token or panel credential. With zend.exception_ignore_args 0 (arguments in
 * traces) and 1.
 */
final class InboundMoveRedactionTest extends IntegrationTestCase
{
    use InboundMoveFixtures;

    /** @return iterable<string, array{string}> */
    public static function ignoreArgs(): iterable
    {
        yield 'arguments in traces' => ['0'];
        yield 'no arguments' => ['1'];
    }

    #[DataProvider('ignoreArgs')]
    public function testNothingSecretLeavesTheRunner(string $ignoreArgs): void
    {
        $before = ini_get('zend.exception_ignore_args');
        ini_set('zend.exception_ignore_args', $ignoreArgs);
        try {
            $this->setUpMove();
            $answered = $this->movableService(1);
            $thrown = $this->movableService(2);
            $leak = static fn (string $email, string $uuid, string $sub): string => sprintf('client %s (%s, sub %s) at %s/secret/panel/api/clients/x/%s token %s vless://%s@h', $email, $uuid, $sub, Fake3xuiPanel::ORIGIN, $email, Fake3xuiPanel::TOKEN, $uuid);
            // The first client's attach fails with the panel quoting it back; the second one's read throws.
            $this->fake->script('POST', 'panel/api/clients/tg_p1_1/attach', Fake3xuiPanel::envelope(false, null, $leak('tg_p1_1', (string) $answered->getClientUuid(), (string) $answered->getSubId())));
            $this->fake->script('GET', 'panel/api/clients/get/tg_p1_2', static function () use ($leak, $thrown): MockResponse {
                throw new \RuntimeException($leak('tg_p1_2', (string) $thrown->getClientUuid(), (string) $thrown->getSubId()));
            });
            $logger = self::getContainer()->get(RecordingLogger::class);
            $logs = count($logger->records);
            $alerts = count($this->http->telegramTexts());
            $this->setting(MultiInboundSettings::ATTACH_MAX_ATTEMPTS, '1', 'number');

            [$code, $output] = $this->command(['--apply' => true]);
            [, $json] = $this->command(['--json' => true]);

            self::assertSame(1, $code, $output);
            self::assertSame(['failed', 'failed'], array_column($this->moves(), 'state'), 'both really failed: the test proves something');
            $texts = [
                'output' => $output,
                'dry run' => $json,
                // The existing per-request log of ModernPanelHttpClient (sanaei_modern_request*, not P4's) names the
                // request path, which holds the client email (its Telegram id is masked by RedactingLogger): left as is.
                'logs' => implode("\n", array_map(static fn (array $r): string => $r['message'].' '.json_encode($r['context'], JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR),
                    array_filter(array_slice($logger->records, $logs), static fn (array $r): bool => !str_starts_with((string) $r['message'], 'sanaei_modern_request')))),
                'stored errors' => implode("\n", array_column($this->moves(), 'last_error')),
                'audit' => (string) json_encode($this->audits(), JSON_UNESCAPED_SLASHES),
                'alerts' => implode("\n", array_slice($this->http->telegramTexts(), $alerts)),
            ];
            self::assertNotSame('', $texts['alerts'], 'given up: alerts were sent');
            foreach ($texts as $where => $text) {
                foreach ([...$this->secretsOf($answered), ...$this->secretsOf($thrown), 'Owner Kaveh', '8940001', '/secret/', 'clients/x/tg_'] as $secret) {
                    self::assertStringNotContainsString($secret, $text, $where);
                }
            }
        } finally {
            ini_set('zend.exception_ignore_args', (string) $before);
        }
    }

    /**
     * PR P6: the same for the retire step (a detach the panel refuses quoting the client, a read that throws), the undo
     * (a read that throws) and the run controls, given up so that every alert is sent.
     */
    #[DataProvider('ignoreArgs')]
    public function testNothingSecretLeavesTheRetireTheUndoOrTheControls(string $ignoreArgs): void
    {
        $before = ini_get('zend.exception_ignore_args');
        ini_set('zend.exception_ignore_args', $ignoreArgs);
        try {
            $this->setUpMove();
            $answered = $this->movableService(1);
            $thrown = $this->movableService(2);
            $undone = $this->movableService(3);
            $leak = static fn (string $email, string $uuid, string $sub): string => sprintf('client %s (%s, sub %s) at %s/secret/panel/api/clients/x/%s token %s vless://%s@h', $email, $uuid, $sub, Fake3xuiPanel::ORIGIN, $email, Fake3xuiPanel::TOKEN, $uuid);
            $this->setting(MultiInboundSettings::ATTACH_MAX_ATTEMPTS, '1', 'number');
            $logger = self::getContainer()->get(RecordingLogger::class);
            $logs = count($logger->records);
            $alerts = count($this->http->telegramTexts());
            [$code, $applied] = $this->command(['--apply' => true]);
            self::assertSame(0, $code, $applied);
            $runId = (int) $this->runs()[0]['id'];

            $this->fake->script('POST', 'panel/api/clients/tg_p1_1/detach', Fake3xuiPanel::envelope(false, null, $leak('tg_p1_1', (string) $answered->getClientUuid(), (string) $answered->getSubId())), 5);
            $this->fake->script('GET', 'panel/api/clients/get/tg_p1_2', static function () use ($leak, $thrown): MockResponse {
                throw new \RuntimeException($leak('tg_p1_2', (string) $thrown->getClientUuid(), (string) $thrown->getSubId()));
            }, 5);
            $this->fake->script('GET', 'panel/api/clients/get/tg_p1_3', static function () use ($leak, $undone): MockResponse {
                throw new \RuntimeException($leak('tg_p1_3', (string) $undone->getClientUuid(), (string) $undone->getSubId()));
            }, 5);
            [, $retireNow] = $this->command(['--retire-now' => (string) $runId, '--service' => (string) $answered->getId()]);
            $this->em->getConnection()->executeStatement("UPDATE service_inbound_move SET detach_after = '2000-01-01 00:00:00' WHERE service_id = ?", [$thrown->getId()]);
            [$code, $retired] = $this->command(['--resume' => (string) $runId]);
            self::assertSame(1, $code, $retired);
            self::assertSame(['failed', 'failed', 'switched'], array_column($this->moves(), 'state'), 'both retires really failed');
            [$code, $undo] = $this->command(['--undo' => (string) $runId]);
            self::assertSame(1, $code, $undo);
            [, $cancel] = $this->command(['--cancel' => (string) $runId, '--json' => true]);

            $texts = [
                'output' => $applied."\n".$retireNow."\n".$retired."\n".$undo."\n".$cancel,
                'logs' => implode("\n", array_map(static fn (array $r): string => $r['message'].' '.json_encode($r['context'], JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR),
                    array_filter(array_slice($logger->records, $logs), static fn (array $r): bool => !str_starts_with((string) $r['message'], 'sanaei_modern_request')))),
                'stored errors' => implode("\n", array_column($this->moves(), 'last_error')),
                'audit' => (string) json_encode($this->audits(), JSON_UNESCAPED_SLASHES),
                'alerts' => implode("\n", array_slice($this->http->telegramTexts(), $alerts)),
            ];
            self::assertStringContainsString('of removing the old inbound', $texts['alerts'], 'given up: alerts were sent');
            self::assertStringContainsString('plan.inbounds_apply_undone', $texts['audit']);
            self::assertStringContainsString('not undone', $undo, 'the undo really failed');
            foreach ($texts as $where => $text) {
                foreach ([...$this->secretsOf($answered), ...$this->secretsOf($thrown), ...$this->secretsOf($undone), 'Owner Kaveh', '8940001', '/secret/', 'clients/x/tg_'] as $secret) {
                    self::assertStringNotContainsString($secret, $text, $where);
                }
            }
        } finally {
            ini_set('zend.exception_ignore_args', (string) $before);
        }
    }

    /**
     * PR P6, D5: a reseller-sold service and a claimed one go through both phases and the undo like any other, nothing
     * names their owner, the reseller or the claimer or shows their links, and the runner writes no owner or reseller row.
     */
    public function testResellerSoldAndClaimedServicesAreNotRevealedAndTheirOwnersAreNotTouched(): void
    {
        $this->setUpMove();
        $reseller = (new Reseller($this->createUser('8930002')->setName('Reseller Rostam')))->setDisplayName('Rostam Shop');
        $this->em->persist($reseller);
        $sold = $this->movableService(1)->setSoldByReseller($reseller);
        $claimed = $this->movableService(2)->setSoldByReseller($reseller)->setUser($this->createUser('8930003')->setName('Claimer Sohrab'));
        $undone = $this->movableService(3)->setSoldByReseller($reseller);
        $this->em->flush();
        $ids = [(int) $sold->getId(), (int) $claimed->getId(), (int) $undone->getId()];
        $secrets = [...$this->secretsOf($sold), ...$this->secretsOf($claimed), ...$this->secretsOf($undone), 'Rostam', 'Sohrab', 'Owner Kaveh', '8930002', '8930003', '8940001'];
        $owners = fn (): array => [
            $this->em->getConnection()->fetchAllAssociative('SELECT * FROM `user` ORDER BY id'),
            $this->em->getConnection()->fetchAllAssociative('SELECT * FROM reseller ORDER BY id'),
            $this->em->getConnection()->fetchFirstColumn('SELECT user_id FROM vpn_service ORDER BY id'),
        ];
        $before = $owners();
        $alerts = count($this->http->telegramTexts());

        [$code, $dry] = $this->command(['--json' => true]);
        self::assertSame(0, $code, $dry);
        [$code, $applied] = $this->command(['--apply' => true]);
        self::assertSame(0, $code, $applied);
        $runId = (int) $this->runs()[0]['id'];
        [$code, $retireNow] = $this->command(['--retire-now' => (string) $runId, '--service' => (string) $ids[0]]);
        self::assertSame(0, $code, $retireNow);
        $this->em->getConnection()->executeStatement("UPDATE service_inbound_move SET detach_after = '2000-01-01 00:00:00' WHERE service_id = ?", [$ids[1]]);
        [$code, $retired] = $this->command(['--resume' => (string) $runId]);
        self::assertSame(0, $code, $retired);
        self::assertSame(['done', 'done', 'switched'], array_column($this->moves(), 'state'));
        [$code, $undo] = $this->command(['--undo' => (string) $runId]);
        self::assertSame(1, $code, $undo); // two services could not be put back: reported, not silently passed
        self::assertSame(['done', 'done', 'undone'], array_column($this->moves(), 'state'));

        self::assertSame($before, $owners(), 'no owner, reseller or ownership change');
        $texts = [
            'output' => implode("
", [$dry, $applied, $retireNow, $retired, $undo]),
            'stored errors' => implode("
", array_column($this->moves(), 'last_error')),
            'audit' => (string) json_encode($this->audits(), JSON_UNESCAPED_SLASHES),
            'alerts' => implode("
", array_slice($this->http->telegramTexts(), $alerts)),
        ];
        foreach ($texts as $where => $text) {
            foreach ($secrets as $secret) {
                self::assertStringNotContainsString($secret, $text, $where);
            }
        }
    }
}
