<?php

declare(strict_types=1);

namespace App\Tests\Bot;

use App\Entity\AdminUser;
use App\Payment\Domain\PaymentStatus;
use App\Tests\Support\BotAdminTestTrait;
use App\Tests\Support\IntegrationTestCase;

/**
 * The bot admin keyboards are built from the admin's permissions: a button is shown only when its action is allowed
 * (the callback is still checked when pressed).
 */
final class BotAdminMenuTest extends IntegrationTestCase
{
    use BotAdminTestTrait;

    private const SUPER = '7101';
    private const ADMIN = '7102';
    private const SUPPORT = '7103';
    private const INACTIVE = '7104';
    private const STRANGER = '7199';

    protected function setUp(): void
    {
        parent::setUp();
        $this->em->getConnection()->executeStatement('DELETE FROM admin_user');
        $this->linkedAdmin('menu-super@shop.test', AdminUser::ROLE_SUPER_ADMIN, self::SUPER);
        $this->linkedAdmin('menu-admin@shop.test', AdminUser::ROLE_ADMIN, self::ADMIN);
        $this->linkedAdmin('menu-support@shop.test', AdminUser::ROLE_SUPPORT, self::SUPPORT);
        $this->linkedAdmin('menu-off@shop.test', AdminUser::ROLE_SUPER_ADMIN, self::INACTIVE, false);
    }

    public function testTheAdminMenuShowsOnlyAllowedButtons(): void
    {
        $everything = ['admin.store_menu', 'admin_pending_payments', 'admin_attention_payments', 'admin_services', 'admin_users', 'main_menu'];
        foreach ([self::ENV_ADMIN => $everything, self::SUPER => $everything, self::ADMIN => $everything, self::SUPPORT => $everything] as $telegramId => $expected) {
            $this->http->reset();
            $this->pressButton((string) $telegramId, 'admin.menu');
            self::assertSame($expected, $this->sentCallbacks(), 'menu for '.$telegramId);
        }

        foreach ([self::INACTIVE, self::STRANGER] as $telegramId) {
            $this->http->reset();
            $this->pressButton($telegramId, 'admin.menu');
            self::assertTrue($this->refused(), 'refused '.$telegramId);
            self::assertSame([], $this->sentCallbacks(), 'no keyboard for '.$telegramId);
        }
    }

    public function testThePaymentDetailOffersModerationOnlyToModerators(): void
    {
        $payment = $this->createPayment($this->createOrder($this->createUser(), $this->createPlan()), null, PaymentStatus::SUBMITTED);
        $id = (int) $payment->getId();

        foreach ([self::SUPPORT => false, self::ADMIN => true] as $telegramId => $moderator) {
            $this->http->reset();
            $this->pressButton((string) $telegramId, 'admin_view_payment:'.$id);
            $callbacks = $this->sentCallbacks();
            self::assertSame($moderator, in_array('admin_confirm_payment:'.$id, $callbacks, true), 'approve for '.$telegramId);
            self::assertSame($moderator, in_array('admin_reject_payment:'.$id, $callbacks, true), 'reject for '.$telegramId);
        }
    }

    public function testTheServiceDetailOffersOnlyAllowedActions(): void
    {
        $service = $this->createActiveService($this->createUser(), $this->createTestInbound());
        $id = (int) $service->getId();
        $manage = ['service_suspend:'.$id, 'service_activate:'.$id, 'service_extend_menu:'.$id, 'service_add_traffic_menu:'.$id, 'service_renew:'.$id, 'service_reset_usage:'.$id];
        $assist = ['service_sync_usage:'.$id, 'service_resend_config:'.$id];

        $this->pressButton(self::SUPPORT, 'admin_service_view:'.$id);
        $support = $this->sentCallbacks();
        foreach ($assist as $callback) {
            self::assertContains($callback, $support);
        }
        foreach ([...$manage, 'service_delete:'.$id] as $callback) {
            self::assertNotContains($callback, $support);
        }

        $this->http->reset();
        $this->pressButton(self::ADMIN, 'admin_service_view:'.$id);
        $admin = $this->sentCallbacks();
        foreach ([...$assist, ...$manage, 'service_delete:'.$id] as $callback) {
            self::assertContains($callback, $admin);
        }

        // After a usage sync in admin mode, the refreshed detail keeps the same filtering.
        $this->http->reset();
        $this->pressButton(self::SUPPORT, 'service_sync_usage:'.$id);
        self::assertNotContains('service_suspend:'.$id, $this->sentCallbacks());
    }

    public function testTheAdminReplyButtonBuildsTheSameMenu(): void
    {
        $this->sendText(self::SUPPORT, '🛠 مدیریت');
        self::assertContains('admin_pending_payments', $this->sentCallbacks());
    }
}
