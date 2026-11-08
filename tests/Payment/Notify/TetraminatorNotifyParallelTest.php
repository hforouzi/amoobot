<?php

declare(strict_types=1);

namespace App\Tests\Payment\Notify;

use App\Entity\Order;
use App\Entity\Payment;
use App\Entity\Setting;
use App\Payment\Domain\PaymentStatus;
use App\Shop\Domain\OrderStatus;
use App\Shop\Domain\OrderType;
use App\Tests\Support\IntegrationTestCase;
use App\Tests\Support\ParallelStart;
use App\Tests\Support\SubprocessAssertions;
use App\Tests\Support\TetraminatorNotifyFixtures;
use App\Wallet\Application\WalletSettings;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Two notify calls with the valid token at the same moment, in two real processes (the provider retrying, or webhook
 * and a second delivery). Each inquiry answers "paid" a second later, so both are in flight together and both reach
 * settle(); the panel sleeps while the first holds the order and payment locks. settle() alone keeps it to one
 * confirmation (design.md decisions 1 and 10).
 */
final class TetraminatorNotifyParallelTest extends IntegrationTestCase
{
    use ParallelStart;
    use SubprocessAssertions;
    use TetraminatorNotifyFixtures;

    private string $panelState = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->panelState = sys_get_temp_dir().'/tetraminator-race-panel-'.bin2hex(random_bytes(6)).'.json';
        $this->installTetraminator();
    }

    protected function tearDown(): void
    {
        @unlink($this->panelState);
        parent::tearDown();
    }

    /**
     * @return array{outputs: list<string>, messages: int, status: mixed, inquiries: int, panelClients: int}
     */
    private function race(Payment $payment): array
    {
        $this->tetraminatorAnswers([self::tetraPaid(sleepMs: 1000), self::tetraPaid(sleepMs: 1000)]);
        $chatId = (string) $payment->getOrder()->getUser()->getTelegramAccount()?->getTelegramId();

        $processes = [];
        foreach ([1, 2] as $_) {
            $process = new Process([(new PhpExecutableFinder())->find(false) ?: 'php', 'tests/Support/bin/tetraminator-notify.php', (string) $payment->getId(), self::TETRA_TOKEN, $chatId, $this->startAt()], dirname(__DIR__, 3), [
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
            $outputs[] = self::assertSubprocessClean($process, 'tetraminator notify race');
        }
        $this->em->clear();
        $state = is_file($this->panelState) ? json_decode((string) file_get_contents($this->panelState), true) : [];

        return [
            'outputs' => $outputs,
            'messages' => array_sum(array_map(static fn (string $o): int => (int) substr($o, (int) strpos($o, ':') + 1), $outputs)),
            'status' => $this->row('payment', (int) $payment->getId())['status'],
            'inquiries' => count($this->tetraminatorCalls()),
            'panelClients' => count($state),
        ];
    }

    public function testTwoValidNotifiesAtOnceConfirmProvisionAndDeliverOnce(): void
    {
        $payment = $this->tetraminatorPayment();

        $result = $this->race($payment);

        self::assertSame(['page', 'page'], array_map(static fn (string $o): string => explode(':', $o)[0], $result['outputs']), 'both answered the neutral page');
        self::assertSame(PaymentStatus::CONFIRMED, $result['status']);
        self::assertContains($result['inquiries'], [1, 2], 'at most one inquiry each');
        self::assertSame(1, $this->countServicesForOrder($payment->getOrder()), 'one service');
        self::assertSame(1, $result['panelClients'], 'one panel client');
        self::assertSame(1, $result['messages'], 'one delivery message: '.implode(', ', $result['outputs']));
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne("SELECT COUNT(*) FROM payment WHERE status = 'confirmed'"));
    }

    public function testTwoValidNotifiesAtOnceCreditAWalletTopUpOnce(): void
    {
        $this->em->persist((new Setting())->setKeyName(WalletSettings::ENABLED)->setValue('true')->setType('boolean'));
        $order = (new Order())->setUser($this->createUser())->setType(OrderType::WALLET_TOPUP)->setAmount(self::TETRA_AMOUNT)->setStatus(OrderStatus::WAITING_PAYMENT);
        $this->em->persist($order);
        $this->em->flush();
        $payment = $this->tetraminatorPayment($order);

        $result = $this->race($payment);

        self::assertSame(PaymentStatus::CONFIRMED, $result['status']);
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM wallet_transaction'), 'one ledger row');
        self::assertSame(self::TETRA_AMOUNT, (int) $this->em->getConnection()->fetchOne('SELECT balance FROM wallet WHERE user_id = ?', [$order->getUser()->getId()]));
        self::assertSame(0, $result['panelClients'], 'a top-up provisions nothing');
    }
}
