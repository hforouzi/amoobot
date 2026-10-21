<?php

declare(strict_types=1);

namespace App\Tests\Admin;

use App\Bot\Application\TelegramUpdateHandler;
use App\Entity\AdminAuditLog;
use App\Entity\AdminUser;
use App\Entity\DiscountCode;
use App\Entity\Plan;
use App\Payment\Application\Settlement\PaymentSettlementService;
use App\Payment\Application\Settlement\VerifiedPayment;
use App\Payment\Domain\Dto\PaymentVerificationResult;
use App\Payment\Domain\PaymentGatewayType;
use App\Payment\Domain\PaymentStatus;
use App\Tests\Support\IntegrationTestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\DomCrawler\Field\ChoiceFormField;

/**
 * Audited admin actions write one AdminAuditLog row (who, source, action, target) and never store a secret.
 */
final class AdminAuditLogTest extends IntegrationTestCase
{
    private const SECRET = 'np-secret-should-not-be-logged-55aa';

    private KernelBrowser $client;
    private AdminUser $super;

    protected function setUp(): void
    {
        parent::setUp();
        $connection = $this->em->getConnection();
        $connection->executeStatement('DELETE FROM admin_audit_log');
        $connection->executeStatement('DELETE FROM admin_user');
        $this->super = (new AdminUser())->setEmail('audit-super@shop.test')->setPassword('unused')->setRoles([AdminUser::ROLE_SUPER_ADMIN])->setIsActive(true)->setTelegramUserId('8001');
        $this->em->persist($this->super);
        $this->em->flush();

        $this->client = new KernelBrowser(self::$kernel);
        $this->client->disableReboot();
        $this->client->loginUser($this->super, 'main');
    }

    protected function tearDown(): void
    {
        $this->em->getConnection()->executeStatement("DELETE FROM discount_code WHERE code LIKE 'AUDIT%'");
        parent::tearDown();
    }

    public function testCrudCreateUpdateDeleteAndAdminDisableAreAudited(): void
    {
        // Create a plan.
        $crawler = $this->client->request('GET', '/admin/plan/new');
        $form = $crawler->filter('form[name="Plan"]')->form();
        $form['Plan[title]'] = 'Audited plan';
        $form['Plan[price]'] = '120000';
        $form['Plan[durationDays]'] = '30';
        $form['Plan[trafficGb]'] = '40';
        $this->client->submit($form);
        self::assertTrue($this->client->getResponse()->isRedirect(), (string) $this->client->getResponse()->getStatusCode());
        $plan = $this->em->getRepository(Plan::class)->findOneBy(['title' => 'Audited plan']);
        self::assertInstanceOf(Plan::class, $plan);
        $this->assertLogged('plan.create', 'Plan', (string) $plan->getId(), AdminAuditLog::SOURCE_WEB);

        // Update its price: the changed field names are recorded (no values).
        $crawler = $this->client->request('GET', '/admin/plan/'.$plan->getId().'/edit');
        $this->client->submit($crawler->filter('form[name="Plan"]')->form(['Plan[price]' => '150000']));
        $log = $this->assertLogged('plan.update', 'Plan', (string) $plan->getId(), AdminAuditLog::SOURCE_WEB);
        self::assertContains('price', $log->getDetails()['changed'] ?? []);

        // Delete a discount code.
        $code = (new DiscountCode())->setCode('AUDIT10')->setType(DiscountCode::TYPE_PERCENT)->setValue(10);
        $this->em->persist($code);
        $this->em->flush();
        $codeId = (string) $code->getId();
        $crawler = $this->client->request('GET', '/admin/discount-code/'.$codeId);
        $this->client->request('POST', '/admin/discount-code/'.$codeId.'/delete', ['token' => $crawler->filter('input[name="token"]')->attr('value')]);
        $this->assertLogged('discount_code.delete', 'DiscountCode', $codeId, AdminAuditLog::SOURCE_WEB);

        // Disable an admin.
        $other = (new AdminUser())->setEmail('audit-other@shop.test')->setPassword('unused')->setRoles([AdminUser::ROLE_ADMIN])->setIsActive(true);
        $this->em->persist($other);
        $this->em->flush();
        $crawler = $this->client->request('GET', '/admin/admin-user/'.$other->getId().'/edit');
        $form = $crawler->filter('form[name="AdminUser"]')->form();
        $active = $form['AdminUser[isActive]'];
        self::assertInstanceOf(ChoiceFormField::class, $active);
        $active->untick();
        $this->client->submit($form);
        $this->assertLogged('admin_user.disable', 'AdminUser', (string) $other->getId(), AdminAuditLog::SOURCE_WEB);
    }

    public function testGatewayAndSettingsChangesAreAuditedWithoutSecrets(): void
    {
        $crawler = $this->client->request('GET', '/admin/payment-gateways/install/'.PaymentGatewayType::NOWPAYMENTS);
        $this->client->submit($crawler->filter('form')->form(), [
            'form[api_key]' => self::SECRET,
            'form[ipn_secret]' => self::SECRET,
            'form[toman_per_usd]' => '60000',
            'form[callback_base_url]' => 'https://shop.test',
        ]);
        self::assertTrue($this->client->getResponse()->isRedirect(), (string) $this->client->getResponse()->getStatusCode());
        $this->assertLogged('payment_gateway.install', 'PaymentGateway', null, AdminAuditLog::SOURCE_WEB);

        $crawler = $this->client->request('GET', '/admin/settings?tab=sales');
        $this->client->submit($crawler->filter('form')->form());
        $this->assertLogged('settings.update', 'Setting', null, AdminAuditLog::SOURCE_WEB);

        $raw = (string) json_encode($this->em->getConnection()->fetchAllAssociative('SELECT * FROM admin_audit_log'));
        self::assertStringNotContainsString(self::SECRET, $raw);
    }

    public function testBotActionsAreAuditedWithTheLinkedAdmin(): void
    {
        $order = $this->createOrder($this->createUser(), $this->createPlan(100000, $this->createTestInbound()));
        $payment = $this->createPayment($order, null, PaymentStatus::SUBMITTED);
        $this->press('8001', 'admin_confirm_payment:'.$payment->getId());
        $this->press('8001', 'admin.p:ak:'.$payment->getId());
        $log = $this->assertLogged('payment.approve', 'Payment', (string) $payment->getId(), AdminAuditLog::SOURCE_BOT);
        self::assertSame($this->super->getId(), $log->getActorAdminUserId());
        self::assertSame('audit-super@shop.test', $log->getActorLabel());

        $service = $this->createActiveService($this->createUser(), $this->createTestInbound());
        $this->press('8001', 'service_suspend:'.$service->getId());
        $this->assertLogged('service.suspend', 'VpnService', (string) $service->getId(), AdminAuditLog::SOURCE_BOT);

        // The env fallback admin (no AdminUser row) is recorded by label.
        $this->press('999000', 'service_extend:'.$service->getId().':30');
        $log = $this->assertLogged('service.extend', 'VpnService', (string) $service->getId(), AdminAuditLog::SOURCE_BOT);
        self::assertNull($log->getActorAdminUserId());
        self::assertSame('TELEGRAM_ADMIN_CHAT_ID', $log->getActorLabel());
    }

    public function testWebPaymentModerationIsAudited(): void
    {
        $order = $this->createOrder($this->createUser(), $this->createPlan(150000, $this->createTestInbound()));
        $payment = $this->createPayment($order, null, PaymentStatus::PENDING);
        self::getContainer()->get(PaymentSettlementService::class)->settle($payment, VerifiedPayment::fromGateway(new PaymentVerificationResult(success: true, paid: true, amount: '100', currency: 'IRR')), 'test');

        $crawler = $this->client->request('GET', '/admin/payment?tab=needs_review');
        $action = (string) $crawler->filter(sprintf('form[action*="/admin/payment/%d/reject"]', $payment->getId()))->attr('action');
        $this->client->request('POST', $action);
        $this->assertLogged('payment.reject', 'Payment', (string) $payment->getId(), AdminAuditLog::SOURCE_WEB);
    }

    public function testAdminCreatedFromTheConsoleIsAudited(): void
    {
        $tester = new CommandTester((new Application(self::$kernel))->find('app:admin:create-user'));
        $tester->execute(['--email' => 'cli-admin@shop.test', '--password' => 'cli-password-should-not-be-logged', '--name' => 'CLI', '--role' => AdminUser::ROLE_SUPPORT], ['interactive' => false]);

        $admin = $this->em->getRepository(AdminUser::class)->findOneBy(['email' => 'cli-admin@shop.test']);
        self::assertInstanceOf(AdminUser::class, $admin);
        $this->assertLogged('admin_user.create', 'AdminUser', (string) $admin->getId(), AdminAuditLog::SOURCE_CLI);
        self::assertStringNotContainsString('cli-password-should-not-be-logged', (string) json_encode($this->em->getConnection()->fetchAllAssociative('SELECT * FROM admin_audit_log')));
    }

    private function assertLogged(string $action, string $targetType, ?string $targetId, string $source): AdminAuditLog
    {
        $this->em->clear();
        $criteria = ['action' => $action, 'targetType' => $targetType] + (null === $targetId ? [] : ['targetId' => $targetId]);
        $logs = $this->em->getRepository(AdminAuditLog::class)->findBy($criteria, ['id' => 'DESC']);
        self::assertCount(1, $logs, sprintf('one %s entry for %s#%s', $action, $targetType, $targetId ?? '*'));
        self::assertSame($source, $logs[0]->getSource());

        return $logs[0];
    }

    private function press(string $telegramId, string $data): void
    {
        self::getContainer()->get(TelegramUpdateHandler::class)->handle([
            'update_id' => random_int(1, PHP_INT_MAX),
            'callback_query' => [
                'id' => 'cb'.random_int(1, PHP_INT_MAX),
                'from' => ['id' => (int) $telegramId, 'is_bot' => false, 'first_name' => 'A'],
                'message' => ['message_id' => 1, 'chat' => ['id' => (int) $telegramId, 'type' => 'private']],
                'data' => $data,
            ],
        ]);
    }
}
