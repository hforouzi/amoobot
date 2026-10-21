<?php

declare(strict_types=1);

namespace App\Tests\Bot;

use App\Bot\Application\Admin\AdminPromptStore;
use App\Entity\AdminAuditLog;
use App\Entity\AdminUser;
use App\Entity\Plan;
use App\Entity\Setting;
use App\Tests\Support\BotAdminTestTrait;
use App\Tests\Support\IntegrationTestCase;

/**
 * Admin "type a value" flows in the bot: validated input, confirmation showing old -> new, audit, a cancel button, a
 * timeout, and no effect on normal customer messages.
 */
final class BotAdminPromptTest extends IntegrationTestCase
{
    use BotAdminTestTrait;

    private const ADMIN = '7302';
    private const SUPER = '7301';
    private const SUPPORT = '7303';
    private const CUSTOMER = '7399';

    protected function setUp(): void
    {
        parent::setUp();
        $connection = $this->em->getConnection();
        $connection->executeStatement('DELETE FROM admin_audit_log');
        $connection->executeStatement('DELETE FROM admin_user');
        $connection->executeStatement("DELETE FROM setting WHERE key_name IN ('automation.batch_limit', 'sales.new_orders_enabled')");
        $this->linkedAdmin('prompt-super@shop.test', AdminUser::ROLE_SUPER_ADMIN, self::SUPER);
        $this->linkedAdmin('prompt-admin@shop.test', AdminUser::ROLE_ADMIN, self::ADMIN);
        $this->linkedAdmin('prompt-support@shop.test', AdminUser::ROLE_SUPPORT, self::SUPPORT);
        foreach ([self::SUPER, self::ADMIN, self::SUPPORT, self::CUSTOMER] as $id) {
            $this->prompts()->clear($id);
        }
    }

    protected function tearDown(): void
    {
        $this->em->getConnection()->executeStatement("DELETE FROM setting WHERE key_name IN ('automation.batch_limit', 'sales.new_orders_enabled')");
        parent::tearDown();
    }

    public function testAPriceChangeIsValidatedConfirmedAndAudited(): void
    {
        $plan = $this->createPlan(120000);
        $id = (int) $plan->getId();

        $this->pressButton(self::ADMIN, 'admin.pl:pr:'.$id);
        self::assertContains('admin.x', $this->sentCallbacks(), 'a cancel button');

        $this->http->reset();
        $this->sendText(self::ADMIN, '12');
        self::assertTrue($this->sentTextContains('عدد معتبر نیست'), 'out of range');
        self::assertSame(120000, $this->price($id));

        $this->http->reset();
        $this->sendText(self::ADMIN, '۱۵۰٬۰۰۰');
        self::assertContains('admin.pl:pk:'.$id.':150000', $this->sentCallbacks(), 'confirmation with the new price');
        self::assertTrue($this->sentTextContains('120,000'));
        self::assertTrue($this->sentTextContains('150,000'));
        self::assertSame(120000, $this->price($id), 'not changed before the confirmation');

        $this->pressButton(self::ADMIN, 'admin.pl:pk:'.$id.':150000');
        self::assertSame(150000, $this->price($id));
        $log = $this->audit('plan.price_update');
        self::assertSame(['before' => 120000, 'after' => 150000], $log->getDetails());
        self::assertSame(AdminAuditLog::SOURCE_BOT, $log->getSource());

        // A forged confirmation with an out-of-range price is refused.
        $this->pressButton(self::ADMIN, 'admin.pl:pk:'.$id.':1');
        self::assertSame(150000, $this->price($id));
    }

    public function testCancelTimeoutAndCommandsEndThePrompt(): void
    {
        $plan = $this->createPlan(120000);
        $id = (int) $plan->getId();

        $this->pressButton(self::ADMIN, 'admin.pl:pr:'.$id);
        $this->pressButton(self::ADMIN, 'admin.x');
        $this->http->reset();
        $this->sendText(self::ADMIN, '150000');
        self::assertNotContains('admin.pl:pk:'.$id.':150000', $this->sentCallbacks(), 'cancelled: the text is an ordinary message');

        $this->prompts()->start(self::ADMIN, 'plan_price', (string) $id, -1);
        $this->http->reset();
        $this->sendText(self::ADMIN, '150000');
        self::assertTrue($this->sentTextContains($this->botText('admin.prompt_expired')));
        self::assertSame(120000, $this->price($id));
        $this->http->reset();
        $this->sendText(self::ADMIN, '150000');
        self::assertFalse($this->sentTextContains($this->botText('admin.prompt_expired')), 'told once');

        $this->pressButton(self::ADMIN, 'admin.pl:pr:'.$id);
        $this->http->reset();
        $this->sendText(self::ADMIN, '/start');
        self::assertTrue($this->sentTextContains($this->botText('bot.welcome')), 'a command is handled as usual');
        $this->http->reset();
        $this->sendText(self::ADMIN, '150000');
        self::assertNotContains('admin.pl:pk:'.$id.':150000', $this->sentCallbacks());
        self::assertSame(120000, $this->price($id));
    }

    public function testAPromptIsNotAnsweredOnceThePermissionIsGoneAndNeverAffectsCustomers(): void
    {
        $plan = $this->createPlan(120000);
        $id = (int) $plan->getId();
        $this->pressButton(self::ADMIN, 'admin.pl:pr:'.$id);
        $this->em->getConnection()->executeStatement('UPDATE admin_user SET roles = ? WHERE telegram_user_id = ?', [json_encode([AdminUser::ROLE_SUPPORT]), self::ADMIN]);
        $this->em->clear();

        $this->http->reset();
        $this->sendText(self::ADMIN, '150000');
        self::assertSame([], array_filter($this->sentCallbacks(), static fn (string $c): bool => str_starts_with($c, 'admin.')), 'no admin answer');
        self::assertSame(120000, $this->price($id));

        // A customer's number goes to the customer flow (there is no prompt for them).
        $this->http->reset();
        $this->sendText(self::CUSTOMER, '150000');
        self::assertFalse($this->sentTextContains('⚠️ تایید عملیات'));
        self::assertSame(0, $this->em->getRepository(AdminAuditLog::class)->count([]));
    }

    public function testBulkPriceAdjustment(): void
    {
        $plan = $this->createPlan(100000);
        $id = (int) $plan->getId();

        $this->pressButton(self::ADMIN, 'admin.pl:bulk');
        $this->http->reset();
        $this->sendText(self::ADMIN, '500');
        self::assertTrue($this->sentTextContains('عدد معتبر نیست'));
        $this->http->reset();
        $this->sendText(self::ADMIN, '10');
        self::assertContains('admin.pl:bk:+10', $this->sentCallbacks());
        self::assertTrue($this->sentTextContains('پلن تغییر می‌کند'));
        self::assertSame(100000, $this->price($id));

        $this->pressButton(self::ADMIN, 'admin.pl:bk:+10');
        self::assertSame(110000, $this->price($id));
        self::assertSame(10.0, $this->audit('plan.bulk_price_adjust')->getDetails()['percent'] ?? null);

        $this->pressButton(self::SUPPORT, 'admin.pl:bk:+10');
        self::assertTrue($this->refused());
        self::assertSame(110000, $this->price($id));
    }

    public function testSettingsSwitchesAndNumbers(): void
    {
        $this->pressButton(self::SUPER, 'admin.s:l');
        foreach ($this->sentTexts() as $text) {
            self::assertStringNotContainsStringIgnoringCase('card', $text, 'no secret settings in the bot');
        }

        $this->pressButton(self::SUPER, 'admin.s:t:0');
        self::assertContains('admin.s:tk:0:0', $this->sentCallbacks());
        $this->pressButton(self::SUPER, 'admin.s:tk:0:0');
        self::assertSame('false', $this->setting('sales.new_orders_enabled'));
        self::assertSame('sales.new_orders_enabled', $this->audit('setting.update')->getTargetId());

        $this->pressButton(self::SUPER, 'admin.s:n:11');
        $this->http->reset();
        $this->sendText(self::SUPER, '5000');
        self::assertTrue($this->sentTextContains('عدد معتبر نیست'));
        $this->http->reset();
        $this->sendText(self::SUPER, '250');
        self::assertContains('admin.s:nk:11:250', $this->sentCallbacks());
        $this->pressButton(self::SUPER, 'admin.s:nk:11:250');
        self::assertSame('250', $this->setting('automation.batch_limit'));

        $this->pressButton(self::SUPER, 'admin.s:nk:11:99999');
        self::assertSame('250', $this->setting('automation.batch_limit'), 'a forged out-of-range value is refused');
    }

    public function testCustomerSearch(): void
    {
        $found = $this->createUser('6612345');
        $found->getTelegramAccount()?->setUsername('findme_buyer');
        $this->createUser('6699999');
        $this->em->flush();

        $this->pressButton(self::SUPPORT, 'admin.c:s');
        $this->http->reset();
        $this->sendText(self::SUPPORT, '@findme');
        self::assertContains('admin_user_view:'.$found->getId(), $this->sentCallbacks());
        self::assertSame(1, count(array_filter($this->sentCallbacks(), static fn (string $c): bool => str_starts_with($c, 'admin_user_view:'))));

        $this->pressButton(self::SUPPORT, 'admin.c:s');
        $this->http->reset();
        $this->sendText(self::SUPPORT, '6612345');
        self::assertContains('admin_user_view:'.$found->getId(), $this->sentCallbacks());
    }

    private function prompts(): AdminPromptStore
    {
        return self::getContainer()->get(AdminPromptStore::class);
    }

    private function price(int $planId): int
    {
        $this->em->clear();

        return (int) $this->em->find(Plan::class, $planId)?->getPrice();
    }

    private function setting(string $key): ?string
    {
        $this->em->clear();

        return $this->em->getRepository(Setting::class)->findOneBy(['keyName' => $key])?->getValue();
    }

    private function audit(string $action): AdminAuditLog
    {
        $this->em->clear();
        $logs = $this->em->getRepository(AdminAuditLog::class)->findBy(['action' => $action], ['id' => 'DESC']);
        self::assertNotEmpty($logs, $action);

        return $logs[0];
    }
}
