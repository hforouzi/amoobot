<?php

declare(strict_types=1);

namespace App\Tests\Payment\Reconcile;

use App\Payment\Application\Reconcile\WinapayReconciler;
use App\Payment\Domain\PaymentStatus;
use App\Tests\Support\IntegrationTestCase;
use App\Tests\Support\ParallelStart;
use App\Tests\Support\SubprocessAssertions;
use App\Tests\Support\WinapayReconcileFixtures;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * The callback and the reconcile for one payment in two real processes. The panel sleeps while the first holds the
 * order and payment locks, so the second is certain to arrive during that settlement.
 */
final class WinapayReconcileParallelTest extends IntegrationTestCase
{
    use ParallelStart;
    use SubprocessAssertions;
    use WinapayReconcileFixtures;

    private string $panelState = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->panelState = sys_get_temp_dir().'/winapay-race-panel-'.bin2hex(random_bytes(6)).'.json';
        $this->installWinapay();
    }

    protected function tearDown(): void
    {
        @unlink($this->panelState);
        parent::tearDown();
    }

    /**
     * @param list<array<string, mixed>> $answers
     *
     * @return array{outputs: list<string>, status: mixed, services: int, panelClients: int}
     */
    private function race(array $answers): array
    {
        $payment = $this->winapayPayment();
        $this->winapayAnswers($answers);
        $id = (string) $payment->getId();

        $processes = [];
        foreach (['callback', 'reconcile'] as $side) {
            $process = new Process([(new PhpExecutableFinder())->find(false) ?: 'php', 'tests/Support/bin/winapay-verify.php', $side, $id, self::AUTHORITY, $this->startAt()], dirname(__DIR__, 3), [
                'APP_ENV' => 'test',
                'TEST_PANEL_STATE_FILE' => $this->panelState,
                'TEST_PANEL_SLEEP_AFTER_CREATE_MS' => '1500',
            ], null, 240);
            $process->start();
            $processes[] = $process;
        }
        $this->releaseStart($processes);

        $outputs = [];
        foreach ($processes as $process) {
            $process->wait();
            $outputs[] = self::assertSubprocessClean($process, 'winapay race');
        }
        sort($outputs);
        $this->em->clear();
        $state = is_file($this->panelState) ? json_decode((string) file_get_contents($this->panelState), true) : [];

        return [
            'outputs' => $outputs,
            'status' => $this->row('payment', (int) $id)['status'],
            'services' => $this->countServicesForOrder($payment->getOrder()),
            'panelClients' => count($state),
        ];
    }

    /**
     * Both sides hold a 100, which Winapay itself should not give: the guard alone has to hold. Which side wins is up
     * to the race, so both results are accepted, as long as exactly one of them is a settlement.
     */
    public function testBothSidesHoldingA100SettleExactlyOnce(): void
    {
        $result = $this->race([self::paid(), self::paid()]);

        self::assertContains($result['outputs'], [
            ['callback:already_processed', 'reconcile:settled'],
            ['callback:already_confirmed', 'reconcile:settled'],
            ['callback:confirmed', 'reconcile:already_settled'],
        ]);
        self::assertSame(PaymentStatus::CONFIRMED, $result['status']);
        self::assertSame(1, $result['services']);
        self::assertSame(1, $result['panelClients']);
        self::assertContains($this->em->getConnection()->fetchOne('SELECT confirmation_source FROM payment'), ['plugin_winapay_callback', WinapayReconciler::SOURCE]);
    }

    /**
     * Winapay as documented: 100 to whichever side asks first, 101 to the other. If the callback holds the 101 and
     * reaches the lock first the payment waits in review, with no service, and the admin decides; that is the one case
     * that does not end confirmed. Never two settlements.
     */
    public function testWinapayAnswering100ThenSettlesAtMostOnce(): void
    {
        $result = $this->race([self::paid(), self::alreadyVerified()]);

        self::assertContains($result['status'], [PaymentStatus::CONFIRMED, PaymentStatus::NEEDS_REVIEW], implode(', ', $result['outputs']));
        $expected = PaymentStatus::CONFIRMED === $result['status'] ? 1 : 0;
        self::assertSame($expected, $result['services']);
        self::assertSame($expected, $result['panelClients']);
    }
}
