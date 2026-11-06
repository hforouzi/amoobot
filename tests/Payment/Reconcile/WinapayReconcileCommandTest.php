<?php

declare(strict_types=1);

namespace App\Tests\Payment\Reconcile;

use Amoobot\Plugin\WinapayScripted\ScriptedWinapayPlugin;
use App\Entity\Payment;
use App\Entity\PaymentGateway;
use App\Entity\Plugin;
use App\Entity\Setting;
use App\Payment\Application\Reconcile\WinapayReconciler;
use App\Payment\Application\Reconcile\WinapayReconcileOutcome;
use App\Payment\Application\Reconcile\WinapayReconcileSettings;
use App\Payment\Application\Webhook\GatewayNotificationProcessor;
use App\Payment\Domain\PaymentStatus;
use App\Payment\Domain\Webhook\NotificationAwareGatewayInterface;
use App\Tests\Support\ConsoleOutputAssertions;
use App\Tests\Support\IntegrationTestCase;
use App\Tests\Support\RecordingLogger;
use App\Tests\Support\WinapayReconcileFixtures;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpFoundation\Request;

final class WinapayReconcileCommandTest extends IntegrationTestCase
{
    use ConsoleOutputAssertions;
    use WinapayReconcileFixtures;

    /**
     * @param array<string, mixed> $options
     */
    private function runCommand(array $options = []): CommandTester
    {
        $tester = new CommandTester((new Application(self::$kernel))->find('app:payments:winapay-reconcile'));
        $tester->execute($options);
        $this->em->clear();

        return $tester;
    }

    private function logger(): RecordingLogger
    {
        return self::getContainer()->get(RecordingLogger::class);
    }

    private function runCallback(PaymentGateway $gateway): string
    {
        $request = Request::create('/payment/callback/plugin/winapay', 'POST', ['Authority' => self::AUTHORITY, 'PaymentStatus' => 'OK']);

        return self::getContainer()->get(GatewayNotificationProcessor::class)->process([$gateway], $request, NotificationAwareGatewayInterface::CHANNEL_CALLBACK, 'plugin_winapay_callback')->code;
    }

    private function assertUntouched(Payment $payment): void
    {
        $row = $this->row('payment', (int) $payment->getId());
        self::assertSame(PaymentStatus::PENDING, $row['status']);
        self::assertNull($row['confirmed_at']);
        self::assertNull($row['verified_at']);
        self::assertNull($row['review_reason']);
        self::assertSame(0, $this->countServicesForOrder($payment->getOrder()));
    }

    private function assertLogsCarryNoSecret(string $text = ''): void
    {
        foreach ([self::AUTHORITY, self::MERCHANT] as $secret) {
            self::assertStringNotContainsString($secret, $this->logger()->text());
            self::assertStringNotContainsString($secret, $text);
        }
    }

    private function assertLogged(string $level, string $fragment): void
    {
        foreach ($this->logger()->records as $record) {
            if ($level === $record['level'] && str_contains($record['message'], $fragment)) {
                $this->addToAssertionCount(1);

                return;
            }
        }
        self::fail(sprintf('No %s record with "%s": %s', $level, $fragment, $this->logger()->text()));
    }

    public function testDryRunIsTheDefaultListsThePaymentAndNeverCallsWinapay(): void
    {
        $this->installWinapay();
        $this->switchReconcile('true');
        $payment = $this->winapayPayment();
        $this->winapayAnswers([self::paid()]);

        $tester = $this->runCommand();

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertConsoleContains('would verify', $tester->getDisplay());
        self::assertConsoleContains('Winapay was not called', $tester->getDisplay());
        self::assertSame([], $this->winapayCalls(), 'a verify in a preview would use up the 100 that --apply needs');
        $this->assertUntouched($payment);
    }

    public function testDryRunWorksWithTheSettingOffAndSaysSo(): void
    {
        $this->installWinapay();
        $payment = $this->winapayPayment();

        $tester = $this->runCommand();

        self::assertConsoleContains('payments.winapay_reconcile_enabled is off', $tester->getDisplay());
        self::assertSame([], $this->winapayCalls());
        $this->assertUntouched($payment);
    }

    /** @return iterable<string, array{?string}> */
    public static function offValues(): iterable
    {
        yield 'no row' => [null];
        yield 'empty' => [''];
        yield 'false' => ['false'];
        yield 'not a boolean' => ['enabled please'];
    }

    #[DataProvider('offValues')]
    public function testApplyDoesNothingUnlessTheSettingIsOn(?string $value): void
    {
        $this->installWinapay();
        if (null !== $value) {
            $this->switchReconcile($value);
        }
        $payment = $this->winapayPayment();
        $this->winapayAnswers([self::paid()]);

        $tester = $this->runCommand(['--apply' => true]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertConsoleContains('payments.winapay_reconcile_enabled is off', $tester->getDisplay());
        self::assertSame([], $this->winapayCalls());
        $this->assertUntouched($payment);
    }

    public function testASeededInstallIsOff(): void
    {
        (new CommandTester((new Application(self::$kernel))->find('app:settings:seed')))->execute([]);

        $setting = $this->em->getRepository(Setting::class)->findOneBy(['keyName' => WinapayReconcileSettings::ENABLED]);
        self::assertInstanceOf(Setting::class, $setting);
        self::assertSame('false', $setting->getValue());
        self::assertSame('boolean', $setting->getType());
        self::assertFalse(self::getContainer()->get(WinapayReconcileSettings::class)->enabled());
    }

    public function testApplySettlesAPaidPaymentWithTheStoredAuthorityAndAmount(): void
    {
        $this->installWinapay();
        $this->switchReconcile('true');
        $payment = $this->winapayPayment();
        $this->winapayAnswers([self::paid()]);

        $tester = $this->runCommand(['--apply' => true]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertConsoleContains('settled: 1', $tester->getDisplay());
        $row = $this->row('payment', (int) $payment->getId());
        self::assertSame(PaymentStatus::CONFIRMED, $row['status']);
        self::assertSame(WinapayReconciler::SOURCE, $row['confirmation_source']);
        self::assertSame(1, $this->countServicesForOrder($payment->getOrder()));

        $calls = $this->winapayCalls();
        self::assertCount(1, $calls);
        self::assertSame('https://winapay.io/webservice/rest/PaymentVerification', $calls[0]['url']);
        parse_str((string) $calls[0]['body'], $sent);
        self::assertSame(['MerchantID' => self::MERCHANT, 'Authority' => self::AUTHORITY, 'Amount' => '150000'], $sent);
        $this->assertLogsCarryNoSecret($tester->getDisplay());
    }

    public function testASecondRunFindsNothing(): void
    {
        $this->installWinapay();
        $this->switchReconcile('true');
        $payment = $this->winapayPayment();
        $this->winapayAnswers([self::paid()]);
        $this->runCommand(['--apply' => true]);

        $tester = $this->runCommand(['--apply' => true]);

        self::assertConsoleContains('No pending Winapay payments', $tester->getDisplay());
        self::assertCount(1, $this->winapayCalls());
        self::assertSame(1, $this->countServicesForOrder($payment->getOrder()));
    }

    /** @return iterable<string, array{int, array<string, mixed>}> */
    public static function otherAmounts(): iterable
    {
        yield 'less' => [149000, []];
        yield 'more' => [151000, []];
        yield 'less, within the gateway tolerance the callback would accept' => [149900, ['underpayment_tolerance_percent' => 5]];
    }

    /**
     * @param array<string, mixed> $config
     */
    #[DataProvider('otherAmounts')]
    public function testAnotherAmountIsNeverSettled(int $amount, array $config): void
    {
        $this->installWinapay();
        $this->switchReconcile('true');
        $payment = $this->winapayPayment($this->winapayGateway($config));
        $this->winapayAnswers([self::paid($amount)]);

        $tester = $this->runCommand(['--apply' => true]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertConsoleContains('amount_mismatch: 1', $tester->getDisplay());
        $this->assertUntouched($payment);
        $this->assertLogged('error', 'amount mismatch');
        $this->assertLogsCarryNoSecret($tester->getDisplay());
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function answersThatLeaveItPending(): iterable
    {
        $notPaid = WinapayReconcileOutcome::NOT_PAID->value;
        $failed = WinapayReconcileOutcome::VERIFY_FAILED->value;

        yield 'network error' => [['transport' => 'Connection timed out'], $failed];
        yield 'not paid, -15' => [['body' => ['Status' => -15]], $notPaid];
        yield 'not paid, -21' => [['body' => ['Status' => -21]], $notPaid];
        yield 'server IP refused, -9' => [['body' => ['Status' => -9]], $failed];
        yield 'paid, wallet update failed, -18' => [['body' => ['Status' => -18]], $failed];
        yield 'could not connect, 102' => [['body' => ['Status' => 102]], $failed];
        yield 'no status' => [['body' => ['Message' => 'maintenance']], $failed];
    }

    /**
     * @param array<string, mixed> $answer
     */
    #[DataProvider('answersThatLeaveItPending')]
    public function testNetworkErrorsAndOtherAnswersLeaveThePaymentPending(array $answer, string $outcome): void
    {
        $this->installWinapay();
        $this->switchReconcile('true');
        $payment = $this->winapayPayment();
        $this->winapayAnswers([$answer]);

        $tester = $this->runCommand(['--apply' => true]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertConsoleContains($outcome.': 1', $tester->getDisplay());
        $this->assertUntouched($payment);
        $this->assertLogged(WinapayReconcileOutcome::NOT_PAID->value === $outcome ? 'info' : 'warning', 'stays pending');
        $this->assertLogsCarryNoSecret($tester->getDisplay());
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function paidAnswersWithoutProof(): iterable
    {
        yield '101, already verified' => [self::alreadyVerified()];
        yield '100 without an amount' => [['body' => ['Status' => 100, 'RefID' => 'REF-1']]];
        yield '100 without a RefID' => [['body' => ['Status' => 100, 'Amount' => 150000]]];
    }

    /**
     * @param array<string, mixed> $answer
     */
    #[DataProvider('paidAnswersWithoutProof')]
    public function testPaidWithoutProofIsLeftPendingForAnAdmin(array $answer): void
    {
        $this->installWinapay();
        $this->switchReconcile('true');
        $payment = $this->winapayPayment();
        $this->winapayAnswers([$answer]);

        $tester = $this->runCommand(['--apply' => true]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertConsoleContains('paid_without_proof: 1', $tester->getDisplay());
        $this->assertUntouched($payment);
        $this->assertLogged('error', 'compare with the Winapay panel');
    }

    public function testSecretsInAnExceptionNeverReachTheLogs(): void
    {
        $this->installWinapay();
        $this->switchReconcile('true');
        $this->winapayPayment();
        $this->winapayAnswers([['transport' => sprintf('POST failed for %s with %s', self::AUTHORITY, self::MERCHANT)]]);

        $tester = $this->runCommand(['--apply' => true]);

        self::assertConsoleContains('verify_failed: 1', $tester->getDisplay());
        $this->assertLogsCarryNoSecret($tester->getDisplay());
        self::assertStringContainsString('[redacted]', $this->logger()->text());
    }

    public function testADisabledPluginFailsTheRunWithoutSecretsInTheLogs(): void
    {
        $this->installWinapay(Plugin::STATUS_DISABLED);
        $this->switchReconcile('true');
        $payment = $this->winapayPayment();

        $tester = $this->runCommand(['--apply' => true]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode(), 'a broken setup is not a quiet success');
        self::assertConsoleContains('error: 1', $tester->getDisplay());
        $this->assertUntouched($payment);
        $this->assertLogged('error', 'driver is unavailable');
        $this->assertLogsCarryNoSecret($tester->getDisplay());
    }

    public function testOnlyPendingWinapayPaymentsInTheWindowAreSelected(): void
    {
        $this->installWinapay();
        $this->switchReconcile('true');
        $gateway = $this->winapayGateway();
        $eligible = $this->winapayPayment($gateway);
        $confirmed = $this->winapayPayment($gateway, status: PaymentStatus::CONFIRMED);
        $submitted = $this->winapayPayment($gateway, status: PaymentStatus::SUBMITTED);
        $tooYoung = $this->winapayPayment($gateway, '-5 minutes');
        $tooOld = $this->winapayPayment($gateway, '-25 hours');
        $noAuthority = $this->winapayPayment($gateway, authority: null);
        $emptyAuthority = $this->winapayPayment($gateway, authority: '');
        $otherPlugin = $this->winapayPayment($this->winapayGateway(pluginCode: 'demo'));
        $inactiveGateway = $this->winapayPayment($this->winapayGateway(active: false));
        $this->winapayAnswers([self::paid()]);

        $tester = $this->runCommand(['--apply' => true]);

        self::assertConsoleContains('settled: 1', $tester->getDisplay());
        self::assertConsoleNotContains('already_settled', $tester->getDisplay(), 'the others are not selected at all');
        self::assertCount(1, $this->winapayCalls());
        self::assertSame(PaymentStatus::CONFIRMED, $this->row('payment', (int) $eligible->getId())['status']);
        foreach ([$tooYoung, $tooOld, $noAuthority, $emptyAuthority, $otherPlugin, $inactiveGateway] as $payment) {
            $this->assertUntouched($payment);
        }
        self::assertSame(PaymentStatus::SUBMITTED, $this->row('payment', (int) $submitted->getId())['status']);
        self::assertSame(0, $this->countServicesForOrder($confirmed->getOrder()));
    }

    public function testTheWindowAndTheBatchLimitAreConfigurable(): void
    {
        $this->installWinapay();
        $this->switchReconcile('true');
        $gateway = $this->winapayGateway();
        $older = $this->winapayPayment($gateway, '-40 hours');
        $newer = $this->winapayPayment($gateway, '-4 minutes');

        $tester = $this->runCommand(['--min-age-minutes' => '2', '--max-age-hours' => '48', '--limit' => '1']);

        self::assertConsoleContains('would verify: 1', $tester->getDisplay());
        self::assertConsoleContains('More than 1 payments match', $tester->getDisplay());
        self::assertMatchesRegularExpression('/\s'.$newer->getId().'\s/', $tester->getDisplay(), 'the newest first');
        self::assertDoesNotMatchRegularExpression('/\s'.$older->getId().'\s+'.$older->getOrder()->getId().'\s/', $tester->getDisplay());
    }

    /** @return iterable<string, array{array<string, string>}> */
    public static function invalidOptions(): iterable
    {
        yield 'negative minimum' => [['--min-age-minutes' => '-1']];
        yield 'not a number' => [['--max-age-hours' => 'soon']];
        yield 'no batch' => [['--limit' => '0']];
        yield 'empty window' => [['--min-age-minutes' => '60', '--max-age-hours' => '1']];
    }

    /**
     * @param array<string, string> $options
     */
    #[DataProvider('invalidOptions')]
    public function testInvalidOptionsAreRefused(array $options): void
    {
        $this->installWinapay();
        $this->switchReconcile('true');
        $payment = $this->winapayPayment();
        $this->winapayAnswers([self::paid()]);

        $tester = $this->runCommand(['--apply' => true] + $options);

        self::assertSame(Command::INVALID, $tester->getStatusCode());
        self::assertSame([], $this->winapayCalls());
        $this->assertUntouched($payment);
    }

    public function testAPaymentSettledAfterTheBatchWasReadIsSkippedWithoutAsking(): void
    {
        $this->installWinapay();
        $gateway = $this->winapayGateway();
        $payment = $this->winapayPayment($gateway);
        $reconciler = self::getContainer()->get(WinapayReconciler::class);
        $batch = $reconciler->candidates(new \DateTimeImmutable('-10 minutes'), new \DateTimeImmutable('-24 hours'), 10);
        self::assertSame([(int) $payment->getId()], array_column($batch, 'id'));
        $this->winapayAnswers([self::paid()]);
        self::assertSame('confirmed', $this->runCallback($gateway));

        self::assertSame(WinapayReconcileOutcome::ALREADY_SETTLED, $reconciler->reconcile($batch[0]['id']));

        self::assertCount(1, $this->winapayCalls(), 'the callback only');
        self::assertSame(1, $this->countServicesForOrder($payment->getOrder()));
        self::assertSame('plugin_winapay_callback', $this->row('payment', (int) $payment->getId())['confirmation_source']);
    }

    public function testAPaymentAnotherProcessSettledIsReadAgainNotTakenFromMemory(): void
    {
        $this->installWinapay();
        $payment = $this->winapayPayment();
        $this->winapayAnswers([self::paid()]);
        $this->em->getConnection()->executeStatement('UPDATE payment SET status = ? WHERE id = ?', [PaymentStatus::CONFIRMED, $payment->getId()]);
        self::assertSame(PaymentStatus::PENDING, $payment->getStatus(), 'still pending in this entity manager');

        $outcome = self::getContainer()->get(WinapayReconciler::class)->reconcile((int) $payment->getId());

        self::assertSame(WinapayReconcileOutcome::ALREADY_SETTLED, $outcome);
        self::assertSame([], $this->winapayCalls());
    }

    public function testACallbackThatSettlesWhileTheVerifyIsOnItsWayWinsAndNothingSettlesTwice(): void
    {
        $this->installWinapay();
        $this->switchReconcile('true');
        $gateway = $this->winapayGateway();
        $payment = $this->winapayPayment($gateway);
        $this->winapayAnswers([self::paid(), self::paid()]);
        ScriptedWinapayPlugin::$beforeAnswer = function () use ($gateway): void {
            ScriptedWinapayPlugin::$beforeAnswer = null;
            self::assertSame('confirmed', $this->runCallback($gateway));
        };

        $tester = $this->runCommand(['--apply' => true]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertConsoleContains('already_settled: 1', $tester->getDisplay());
        self::assertSame('plugin_winapay_callback', $this->row('payment', (int) $payment->getId())['confirmation_source']);
        self::assertSame(1, $this->countServicesForOrder($payment->getOrder()));
    }

    public function testACallbackThatParkedThePaymentWhileThisRunHoldsTheProofTellsTheAdmin(): void
    {
        $this->installWinapay();
        $this->switchReconcile('true');
        $payment = $this->winapayPayment();
        $this->winapayAnswers([self::paid()]);
        ScriptedWinapayPlugin::$beforeAnswer = function () use ($payment): void {
            $this->em->getConnection()->executeStatement('UPDATE payment SET status = ? WHERE id = ?', [PaymentStatus::NEEDS_REVIEW, $payment->getId()]);
        };

        $tester = $this->runCommand(['--apply' => true]);

        self::assertConsoleContains('needs_review: 1', $tester->getDisplay());
        self::assertSame(PaymentStatus::NEEDS_REVIEW, $this->row('payment', (int) $payment->getId())['status']);
        self::assertSame(0, $this->countServicesForOrder($payment->getOrder()));
        $this->assertLogged('error', 'An admin can approve it');
    }
}
