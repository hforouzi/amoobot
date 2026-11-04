<?php

declare(strict_types=1);

namespace App\Tests\Shop\InboundMove;

use App\Entity\Payment;
use App\Payment\Application\Settlement\PaymentSettlementService;
use App\Payment\Application\Settlement\SettlementOutcome;
use App\Payment\Domain\PaymentStatus;
use App\Provisioning\Application\InboundMovePlanner;
use App\Provisioning\Application\InboundMoveScope;
use App\Tests\Support\IntegrationTestCase;
use App\Tests\Support\SubprocessAssertions;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * PR P4, P-7 of docs/design/plan-inbound-change.md 8.2, in real time like MultiInboundLockHoldTest: a slow panel (every
 * call DELAY_MS), a settlement of a new order of the plan, and another process waiting for that order's row
 * (lock-order-row.php). Measured once alone, once while the move runner works through the plan's services in a third
 * process: the wait for the order row is the same (the runner never locks an order, payment, wallet, user or reseller
 * row, 5.3), and the new service gets the plan's current primary without joining the run (5.7).
 */
#[Group('real-time')]
final class InboundMoveLockHoldTest extends IntegrationTestCase
{
    use InboundMoveFixtures;
    use SubprocessAssertions;

    private const DELAY_MS = 1500;
    private const LOCK_WAIT_S = 60;
    /** What the measurements may differ by on a loaded machine. */
    private const TOLERANCE_S = 3.0;

    public function testTheSettlementsOrderRowWaitIsTheSameWhileARunnerWorks(): void
    {
        $this->setUpMove();
        $dir = sys_get_temp_dir().'/amoobot-p4-lock-'.bin2hex(random_bytes(4));
        mkdir($dir);
        try {
            $services = array_map(fn (int $n) => $this->movableService($n), range(1, 3));
            $this->fake->stateFile = $dir.'/panel.json';
            $this->fake->saveState();
            $this->fake->delays = ['(GET|POST) .*' => self::DELAY_MS];
            $this->em->getProxyFactory()->generateProxyClasses($this->em->getMetadataFactory()->getAllMetadata());

            $alone = $this->settleWhileAnotherProcessWaits();

            $this->em->clear();
            $created = self::getContainer()->get(InboundMovePlanner::class)->create($this->managed($this->gold), new InboundMoveScope());
            self::assertIsArray($created);
            $runner = new Process([$this->php(), 'tests/Support/bin/inbound-move.php', 'resume', (string) $created[0], $dir.'/panel.json', $dir, 'after|GET panel/api/clients/get/tg_p1_1|1', (string) self::DELAY_MS], dirname(__DIR__, 3), ['APP_ENV' => 'test'], null, 240);
            $runner->start();
            $until = microtime(true) + 120;
            while (!is_file($dir.'/ready') && $runner->isRunning() && microtime(true) < $until) {
                usleep(25_000);
            }
            self::assertFileExists($dir.'/ready', $runner->getErrorOutput());
            // The runner is between its read and its attach: from here both work at once.
            touch($dir.'/go');
            $during = $this->settleWhileAnotherProcessWaits();
            $runner->wait();

            self::assertStringStartsWith('exit=0', self::assertSubprocessClean($runner, 'runner'));
            self::assertLessThan($alone['waited'] + self::TOLERANCE_S, $during['waited'], sprintf('the order row wait is unchanged: %.1f s alone, %.1f s during the run', $alone['waited'], $during['waited']));
            self::assertSame(array_fill(0, 3, 'switched'), array_column($this->moves(), 'state'));
            foreach ($services as $service) {
                self::assertSame((string) $this->nl->getId(), (string) $this->serviceRow((int) $service->getId())['inbound_id']);
            }
            self::assertSame([(string) $this->nl->getId()], array_values(array_unique(array_map('strval', $this->em->getConnection()->fetchFirstColumn('SELECT inbound_id FROM vpn_service WHERE id NOT IN (SELECT service_id FROM service_inbound_move)')))), 'the new sales got the current primary');
            self::assertCount(3, $this->moves(), 'and did not join the frozen run');
            fwrite(STDERR, sprintf("\n[P-7] delay %d ms: the order row waited %.1f s alone, %.1f s while the runner worked\n", self::DELAY_MS, $alone['waited'], $during['waited']));
        } finally {
            foreach (glob($dir.'/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($dir);
        }
    }

    /** @return array{waited: float} */
    private function settleWhileAnotherProcessWaits(): array
    {
        $order = $this->createOrder($this->managed($this->owner), $this->managed($this->gold));
        $payment = $this->createPayment($order, null, PaymentStatus::SUBMITTED);
        $other = new Process([$this->php(), 'tests/Support/bin/lock-order-row.php', (string) $order->getId(), (string) self::LOCK_WAIT_S], dirname(__DIR__, 3), ['APP_ENV' => 'test'], null, 240);
        $other->start();

        $this->em->clear();
        $outcome = self::getContainer()->get(PaymentSettlementService::class)->approveByAdmin($this->em->find(Payment::class, $payment->getId()) ?? self::fail('payment'), 1, null, 'test')->outcome;
        $other->wait();

        self::assertSame(SettlementOutcome::CONFIRMED, $outcome);
        $seen = json_decode(trim($other->getOutput()), true);
        self::assertIsArray($seen, "the other process said nothing.\n".$other->getErrorOutput());
        self::assertTrue($seen['ok'], sprintf('the other process could not take the order row: %s', (string) $seen['error']));

        return ['waited' => (float) $seen['waited']];
    }

    private function php(): string
    {
        return (new PhpExecutableFinder())->find(false) ?: PHP_BINARY;
    }
}
