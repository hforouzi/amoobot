<?php

declare(strict_types=1);

namespace App\Tests\Shop\InboundMove;

use App\Entity\Payment;
use App\Payment\Application\Settlement\PaymentSettlementService;
use App\Payment\Application\Settlement\SettlementOutcome;
use App\Payment\Domain\PaymentStatus;
use App\Provisioning\Application\InboundMoveScope;
use App\Tests\Support\IntegrationTestCase;
use App\Tests\Support\SubprocessAssertions;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * PR P11c, docs/design/plan-inbound-add-remove.md 11.1 "No effect on sales", in real time like InboundMoveLockHoldTest: a
 * slow panel, a settlement of a new order of the plan, and another process waiting for that order's row
 * (lock-order-row.php). Measured once alone, once while a remove run works through the plan's services in a third
 * process: the wait for the order row is the same, and the new service does not join the frozen run.
 */
#[Group('real-time')]
final class InboundMoveRemoveLockHoldTest extends IntegrationTestCase
{
    use InboundRemoveFixtures;
    use SubprocessAssertions;

    private const DELAY_MS = 1500;
    private const LOCK_WAIT_S = 60;
    /** What the measurements may differ by on a loaded machine. */
    private const TOLERANCE_S = 3.0;

    public function testTheSettlementsOrderRowWaitIsTheSameWhileARemoveRuns(): void
    {
        $this->setUpRemove();
        $dir = sys_get_temp_dir().'/amoobot-p11c-lock-'.bin2hex(random_bytes(4));
        mkdir($dir);
        try {
            $services = array_map(fn (int $n) => $this->onBoth($n), range(1, 3));
            $this->fake->stateFile = $dir.'/panel.json';
            $this->fake->saveState();
            $this->fake->delays = ['(GET|POST) .*' => self::DELAY_MS];
            $this->em->getProxyFactory()->generateProxyClasses($this->em->getMetadataFactory()->getAllMetadata());

            $alone = $this->settleWhileAnotherProcessWaits();

            $runId = $this->plannedRemove(new InboundMoveScope(limit: 3));
            $runner = new Process([$this->php(), 'tests/Support/bin/inbound-move.php', 'resume', (string) $runId, $dir.'/panel.json', $dir, 'after|GET panel/api/clients/get/tg_p1_1|1', (string) self::DELAY_MS], dirname(__DIR__, 3), ['APP_ENV' => 'test'], null, 240);
            $runner->start();
            $until = microtime(true) + 120;
            while (!is_file($dir.'/ready') && $runner->isRunning() && microtime(true) < $until) {
                usleep(25_000);
            }
            self::assertFileExists($dir.'/ready', $runner->getErrorOutput());
            // The runner is between its read and its detach: from here both work at once.
            touch($dir.'/go');
            $during = $this->settleWhileAnotherProcessWaits();
            $runner->wait();

            self::assertStringStartsWith('exit=0', self::assertSubprocessClean($runner, 'runner'));
            self::assertLessThan($alone['waited'] + self::TOLERANCE_S, $during['waited'], sprintf('the order row wait is unchanged: %.1f s alone, %.1f s during the run', $alone['waited'], $during['waited']));
            self::assertSame(array_fill(0, 3, 'done'), array_column($this->moves(), 'state'));
            foreach ($services as $service) {
                self::assertNull($this->nlRow($service));
            }
            self::assertCount(3, $this->moves(), 'the new sales did not join the frozen run');
            fwrite(STDERR, sprintf("\n[P11c] delay %d ms: the order row waited %.1f s alone, %.1f s while the remove worked\n", self::DELAY_MS, $alone['waited'], $during['waited']));
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
