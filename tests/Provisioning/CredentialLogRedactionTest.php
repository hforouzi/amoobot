<?php

declare(strict_types=1);

namespace App\Tests\Provisioning;

use App\Bot\Application\BotMessageLogger;
use App\Entity\BotMessageLog;
use App\Entity\TrialClaim;
use App\Entity\TrialPlan;
use App\Entity\VpnInbound;
use App\Entity\VpnService;
use App\Payment\Application\Settlement\PaymentSettlementService;
use App\Payment\Application\Settlement\SettlementOutcome;
use App\Payment\Domain\PaymentStatus;
use App\Provisioning\Application\FinalConfigLinkProvider;
use App\Provisioning\Application\VpnProvisioningService;
use App\Shop\Application\TrialAccountService;
use App\Tests\Support\CapturesErrorLog;
use App\Tests\Support\IntegrationTestCase;

/**
 * A customer's access (subscription URL, config links, client UUID, subscription id, /s/ page token) never reaches a
 * log line or a stored message: whoever reads the logs or the admin tables could use the service with it.
 */
final class CredentialLogRedactionTest extends IntegrationTestCase
{
    use CapturesErrorLog;

    private const UUID = '9b1deb4d-3b7d-4bad-9bdd-2b0d7b3dcb6d';
    private const SUB_ID = 'subLeak0123456789';
    private const PAGE_TOKEN = 'PageTok_0123456789abcdefghijklmnopqrstuvwxy';

    /** @return array<string, string> */
    private static function secrets(): array
    {
        return [
            'client UUID' => self::UUID,
            'subscription id' => self::SUB_ID,
            'subscription URL' => 'https://sub.panel.test:2096/sub/'.self::SUB_ID,
            'config link' => 'vless://'.self::UUID.'@edge.panel.test:443?type=tcp&security=none#me',
            'page token' => self::PAGE_TOKEN,
        ];
    }

    /** What a failing panel call or query may quote: every form of the secrets above. */
    private static function leakyMessage(): string
    {
        return sprintf(
            'An exception occurred while executing a query: SQLSTATE[23000] for %s and %s, uuid=%s sub_id=%s, page https://bot.test/s/%s with params ["%s", "%s"]',
            self::secrets()['subscription URL'],
            self::secrets()['config link'],
            self::UUID,
            self::SUB_ID,
            self::PAGE_TOKEN,
            self::SUB_ID,
            self::UUID
        );
    }

    public function testTheProvisioningLogNamesTheServiceButNotItsAccess(): void
    {
        $order = $this->createOrder($this->createUser(), $this->createPlan(150000, $this->createTestInbound()));
        $service = null;

        $log = $this->captureErrorLog(function () use ($order, &$service): void {
            $service = self::getContainer()->get(VpnProvisioningService::class)->provisionOrder($order, ['source' => 'test']);
        });

        self::assertInstanceOf(VpnService::class, $service);
        self::assertNotSame('', (string) $service->getSubscriptionUrl());
        self::assertNotSame([], $service->getConfigLinks());
        self::assertContainsNoSecret($log, [
            'client UUID' => $service->getClientUuid(),
            'subscription id' => $service->getSubId(),
            'subscription URL' => $service->getSubscriptionUrl(),
            'config text' => $service->getConfigText(),
        ] + array_combine(array_map(static fn (int $i): string => 'config link '.$i, array_keys($service->getConfigLinks())), $service->getConfigLinks()), 'the provisioning log');
        self::assertStringContainsString(sprintf('provisioning_config source="test" service_id=%d order_id=%d', $service->getId(), $order->getId()), $log);
        self::assertStringContainsString('uuid="••••'.substr((string) $service->getClientUuid(), -4).'"', $log, 'the last 4 characters stay for correlation');
        self::assertStringContainsString('subscription_url_present=yes', $log);
    }

    public function testAFailedProvisioningStoresAndLogsNoAccess(): void
    {
        $order = $this->createOrder($this->createUser(), $this->createPlan(150000, $this->createTestInbound()));
        $payment = $this->createPayment($order, null, PaymentStatus::SUBMITTED);
        $this->driver()->failNext(1, self::leakyMessage());
        $result = null;

        $log = $this->captureErrorLog(function () use ($payment, &$result): void {
            $result = self::getContainer()->get(PaymentSettlementService::class)->approveByAdmin($payment, 1, null, 'test');
        });

        self::assertSame(SettlementOutcome::PROVISIONING_FAILED, $result?->outcome);
        $stored = (string) $this->em->getConnection()->fetchOne('SELECT provisioning_error FROM payment WHERE id = ?', [$payment->getId()]);
        self::assertStringContainsString('SQLSTATE[23000]', $stored, 'the error stays readable for support');
        self::assertStringContainsString('https://sub.panel.test:2096/', $stored, 'the host stays');
        self::assertContainsNoSecret($stored, self::secrets(), 'payment.provisioning_error');
        self::assertContainsNoSecret($log, self::secrets(), 'the settlement log');
    }

    public function testAFailedTrialStoresNoAccess(): void
    {
        $inbound = $this->createTestInbound();
        $trial = (new TrialPlan())->setTitle('trial')->setIsActive(true)->setDurationDays(1)->setTrafficGb(1)->setInbound($inbound)->setMaxClaimsPerUser(1);
        $this->em->persist($trial);
        $this->em->flush();
        $account = $this->createUser()->getTelegramAccount() ?? throw new \LogicException('user without telegram account');
        $this->driver()->failNext(1, self::leakyMessage());
        $result = null;

        $log = $this->captureErrorLog(function () use ($account, $trial, &$result): void {
            $result = self::getContainer()->get(TrialAccountService::class)->claim($account, $trial);
        });

        $claim = $result?->claim;
        self::assertInstanceOf(TrialClaim::class, $claim);
        self::assertSame(TrialClaim::STATUS_FAILED, $claim->getStatus());
        self::assertStringContainsString('SQLSTATE[23000]', (string) $claim->getFailureReason());
        self::assertContainsNoSecret((string) $claim->getFailureReason(), self::secrets(), 'trial_claim.failure_reason');
        self::assertContainsNoSecret($log, self::secrets(), 'the trial log');
    }

    public function testAFailedLinkGenerationLogsNoAccess(): void
    {
        $service = new class extends VpnService {
            public function getInbound(): ?VpnInbound
            {
                throw new \RuntimeException(CredentialLogRedactionTest::leaky());
            }
        };
        $service->setClientUuid(self::UUID)->setSubId(self::SUB_ID)->setSubscriptionUrl(self::secrets()['subscription URL']);

        $log = $this->captureErrorLog(static function () use ($service): void {
            self::getContainer()->get(FinalConfigLinkProvider::class)->getFinalLinksForService($service, 'test');
        });

        self::assertStringContainsString('[FinalConfigLinkProvider] generate_failed', $log);
        self::assertContainsNoSecret($log, self::secrets(), 'the link generation log');
    }

    public function testTheBotMessageLogKeepsTheMessageButNotTheAccessItCarried(): void
    {
        $text = sprintf("Your service is ready.\nSubscription: %s\nConfig:\n%s\nPage: https://bot.test/s/%s\nID %s",
            self::secrets()['subscription URL'], self::secrets()['config link'], self::PAGE_TOKEN, self::UUID);

        self::getContainer()->get(BotMessageLogger::class)->log('out', ['method' => 'sendMessage', 'chat_id' => 42, 'text' => $text, 'reply_markup' => ['inline_keyboard' => [[['text' => 'Open', 'url' => 'https://bot.test/s/'.self::PAGE_TOKEN]]]]], '42', 'sendMessage');

        $this->em->clear();
        $row = $this->em->getRepository(BotMessageLog::class)->findOneBy([], ['id' => 'DESC']);
        self::assertInstanceOf(BotMessageLog::class, $row);
        $stored = (string) json_encode($row->getPayload(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        self::assertContainsNoSecret($stored, self::secrets(), 'bot_message_log.payload');
        self::assertStringContainsString("Your service is ready.\nSubscription: https://sub.panel.test:2096/[redacted]\nConfig:\nvless://[redacted]", (string) $row->getPayload()['text'], 'the text and its line breaks stay');
        self::assertSame(42, $row->getPayload()['chat_id']);
    }

    /** @internal for the anonymous VpnService above */
    public static function leaky(): string
    {
        return self::leakyMessage();
    }
}
