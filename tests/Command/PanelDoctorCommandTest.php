<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Entity\VpnPanel;
use App\Provisioning\Domain\SanaeiIntegrationMode;
use App\Tests\Support\Fake3xui\Fake3xuiPanel;
use App\Tests\Support\IntegrationTestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * app:panel:doctor against the fake 3x-ui: the connection report, and the self-test lifecycle on a temporary client
 * (refused without --yes, cleans up after a failure, never touches other clients, never prints the token).
 */
final class PanelDoctorCommandTest extends IntegrationTestCase
{
    private Fake3xuiPanel $fake;
    private VpnPanel $panel;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fake = new Fake3xuiPanel();
        $this->http->on('panel.example.test', $this->fake);
        // A real customer's client that the doctor must never touch.
        $this->fake->clients['customer@shop'] = [
            'email' => 'customer@shop', 'id' => '99999999-9999-4999-8999-999999999999', 'subId' => 'custsub', 'flow' => '', 'totalGB' => 5 * 1073741824,
            'expiryTime' => 0, 'limitIp' => 1, 'limitHwid' => 0, 'tgId' => 7, 'comment' => 'real', 'enable' => true, 'group' => '', 'reset' => 0, 'inboundIds' => [1], 'dbId' => 1,
        ];
        $this->panel = (new VpnPanel())->setType('sanaei_3xui')->setTitle('Prod')->setBaseUrl(Fake3xuiPanel::ORIGIN.'/secret/')->setPlainApiToken(Fake3xuiPanel::TOKEN);
        $this->em->persist($this->panel);
        $this->em->flush();
    }

    public function testReport(): void
    {
        [$code, $out] = $this->doctor([]);

        self::assertSame(Command::SUCCESS, $code, $out);
        self::assertStringContainsString('PASS  connection (modern API)', $out);
        self::assertMatchesRegularExpression('/reachable\s+yes/', $out);
        self::assertMatchesRegularExpression('/tls\s+verified/', $out);
        self::assertMatchesRegularExpression('/inbounds\s+3/', $out);
        self::assertMatchesRegularExpression('/latency\s+\d+ ms/', $out);
        self::assertStringContainsString('••••FAKE', $out);
        $this->em->clear();
        self::assertSame(SanaeiIntegrationMode::MODERN, $this->em->find(VpnPanel::class, $this->panel->getId())?->getDetectedIntegrationMode(), 'the probe is stored');
    }

    public function testReportOfAFailure(): void
    {
        $this->fake->tokenRevoked = true;
        [$code, $out] = $this->doctor(['--locale' => 'fa']);

        self::assertSame(Command::FAILURE, $code);
        self::assertStringContainsString('FAIL  auth_rejected', $out);
        self::assertStringContainsString('Settings → Security → API Token', $out, 'the fix');
    }

    public function testTheSelfTestNeedsYes(): void
    {
        [$code, $out] = $this->doctor(['--self-test' => true]);

        self::assertSame(Command::INVALID, $code);
        self::assertStringContainsString('--yes', $out);
        self::assertSame([], $this->fake->requests, 'nothing was sent to the panel');
    }

    public function testTheSelfTestRunsTheWholeLifecycleAndCleansUp(): void
    {
        $customer = $this->fake->clients['customer@shop'];
        [$code, $out] = $this->doctor(['--self-test' => true, '--yes' => true]);

        self::assertSame(Command::SUCCESS, $code, $out);
        foreach (['name is free', 'create', 'read', 'update quota/expiry', 'disable', 'enable', 'batch usage', 'delete'] as $step) {
            self::assertMatchesRegularExpression('/^PASS  '.preg_quote($step, '/').'/m', $out, $step);
        }
        self::assertStringContainsString('RESULT: OK', $out);
        self::assertSame(['customer@shop'], array_keys($this->fake->clients), 'only the temporary client was created and removed');
        self::assertSame($customer, $this->fake->clients['customer@shop'], 'the customer client is untouched');
        $this->assertOnlySelfTestClientWritten();
        $this->assertNoSecret($out);
    }

    public function testAFailedSelfTestStillCleansUp(): void
    {
        // The panel acknowledges updates but does not apply them.
        [$code, $out] = $this->runWithIgnoredUpdate();

        self::assertSame(Command::FAILURE, $code, $out);
        self::assertMatchesRegularExpression('/^FAIL  update quota\/expiry/m', $out);
        // \r?: the console output ends lines with PHP_EOL (\r\n on Windows).
        self::assertMatchesRegularExpression('/^CLEANUP  removed amoobot_selftest_[0-9a-f]{8}\r?$/m', $out);
        self::assertStringContainsString('RESULT: FAILED', $out);
        self::assertSame(['customer@shop'], array_keys($this->fake->clients));
        $this->assertOnlySelfTestClientWritten();
        $this->assertNoSecret($out);
    }

    /**
     * @return array{int, string}
     */
    private function runWithIgnoredUpdate(): array
    {
        $fake = new Fake3xuiPanel();
        $fake->clients = $this->fake->clients;
        $this->fake = $fake;
        $this->http->on('panel.example.test', function (string $method, string $url, array $options) use ($fake) {
            if ('POST' === $method && str_contains($url, '/panel/api/clients/update/')) {
                return Fake3xuiPanel::envelope(true, null, 'ok'); // acknowledged, not applied
            }

            return $fake($method, $url, $options);
        });

        return $this->doctor(['--self-test' => true, '--yes' => true]);
    }

    private function assertOnlySelfTestClientWritten(): void
    {
        foreach ($this->fake->requests as $request) {
            if ('POST' === $request['method']) {
                self::assertStringNotContainsString('customer', $request['path'].json_encode($request['body']), 'a write touched another client');
            }
        }
    }

    private function assertNoSecret(string $out): void
    {
        self::assertStringNotContainsString(Fake3xuiPanel::TOKEN, $out);
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array{int, string}
     */
    private function doctor(array $options): array
    {
        $tester = new CommandTester((new Application(self::$kernel))->find('app:panel:doctor'));
        $code = $tester->execute(['panelId' => (string) $this->panel->getId()] + $options);

        return [$code, $tester->getDisplay()];
    }
}
