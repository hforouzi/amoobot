<?php

declare(strict_types=1);

namespace App\Tests\MiniApp\Admin;

use App\Entity\Payment;
use App\Payment\Domain\PaymentStatus;
use App\Shop\Domain\OrderStatus;
use App\Tests\Support\BotAdminTestTrait;
use App\Tests\Support\IntegrationTestCase;
use App\Tests\Support\MiniAppAdminFixtures;
use App\Tests\Support\MiniAppTestTrait;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Payments and orders in the Mini App admin: lists, detail, the receipt endpoint, and the moderation actions through
 * PaymentModeration with CSRF, confirm token, idempotency, rate limit and audit.
 */
final class MiniAppAdminPaymentsTest extends IntegrationTestCase
{
    use BotAdminTestTrait;
    use MiniAppAdminFixtures;
    use MiniAppTestTrait;

    private const JPEG = "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00\xFF\xD9";

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAdminCast();
        $this->startMiniAppClient();
    }

    public function testListsFilterByStatusTabAndSearchByTrackingCode(): void
    {
        $session = $this->authenticate(self::SUPPORT);
        $tracking = (string) $this->payment->getOrder()->getTrackingCode();

        $list = new Crawler($this->appGet('/app/admin/payments?tab=pending', $session));
        self::assertSame(200, $this->httpStatus());
        self::assertSame([(string) $this->payment->getId()], $list->filter('[data-payment-id]')->each(static fn (Crawler $c): string => (string) $c->attr('data-payment-id')));
        self::assertSame('pending', $list->filter('.chip.active')->attr('data-chip'));

        self::assertCount(0, (new Crawler($this->appGet('/app/admin/payments?tab=confirmed', $session)))->filter('[data-payment-id]'));
        self::assertCount(1, (new Crawler($this->appGet('/app/admin/payments?q='.urlencode($tracking), $session)))->filter('[data-payment-id]'));
        $none = new Crawler($this->appGet('/app/admin/payments?q=NOPE-404', $session));
        self::assertCount(1, $none->filter('[data-empty]'));

        $orders = new Crawler($this->appGet('/app/admin/orders?status='.$this->payment->getOrder()->getStatus(), $session));
        self::assertSame(200, $this->httpStatus());
        self::assertCount(1, $orders->filter('[data-order-id]'));
        $this->appGet('/app/admin/orders/'.$this->payment->getOrder()->getId(), $session);
        self::assertSame(200, $this->httpStatus());
    }

    public function testTheDetailShowsWhatAnAdminNeedsAndNoSecrets(): void
    {
        $this->payment->getGateway()?->setManualCardNumber('6037-0000-1111-2222')->setConfig(['api_key' => 'gw-secret-key-123']);
        $this->payment->setProvisioningError('Panel https://panel.example:2053/x?token=abc123secret refused (password=hunter2)');
        $this->em->flush();

        foreach ([self::SUPPORT, self::ADMIN] as $who) {
            $html = $this->appGet('/app/admin/payments/'.$this->payment->getId(), $this->authenticate($who));
            self::assertSame(200, $this->httpStatus());
            foreach (['gw-secret-key-123', 'abc123secret', 'hunter2', 'panel.example', 'AgAD-receipt', 'api.telegram.org'] as $secret) {
                self::assertStringNotContainsString($secret, $html, $who.' sees '.$secret);
            }
            $page = new Crawler($html);
            self::assertCount(1, $page->filter('img[data-auth-src="/app/api/admin/payments/'.$this->payment->getId().'/receipt"]'));
            self::assertCount(1, $page->filter('[data-provisioning-error]'));
            self::assertDoesNotMatchRegularExpression(self::RAW_KEY, strip_tags($html));
        }
    }

    public function testSupportSeesNoModerationButtonsAndGets403ForEveryAction(): void
    {
        $session = $this->authenticate(self::SUPPORT);
        $page = new Crawler($this->appGet('/app/admin/payments/'.$this->payment->getId(), $session));
        self::assertCount(0, $page->filter('[data-admin-action]'));
        self::assertNull($page->filter('.view-root')->attr('data-main-button-text'));

        foreach (['approve', 'retry', 'reject', 'reject-refunded'] as $action) {
            $this->appPostJson('/app/api/admin/payments/'.$this->payment->getId().'/'.$action, $session, [], self::newKey());
            self::assertSame(403, $this->httpStatus(), $action);
        }
        $this->em->clear();
        self::assertSame(PaymentStatus::SUBMITTED, $this->em->find(Payment::class, $this->payment->getId())?->getStatus());
        self::assertSame([], $this->auditRows());
    }

    public function testApproveNeedsCsrfAConfirmTokenAndRunsOnceThroughTheModerationService(): void
    {
        $session = $this->authenticate(self::ADMIN);
        $path = '/app/api/admin/payments/'.$this->payment->getId().'/approve';

        $page = new Crawler($this->appGet('/app/admin/payments/'.$this->payment->getId(), $session));
        self::assertSame(['approve', 'reject'], $page->filter('[data-action-bar] [data-admin-action]')->each(static fn (Crawler $c): string => (string) $c->attr('data-admin-action')));
        self::assertSame('approve', $page->filter('.view-root')->attr('data-main-button-action'));

        $this->appPostJson($path, $session, [], self::newKey(), 'wrong-csrf');
        self::assertSame(403, $this->httpStatus());
        self::assertSame('csrf', $this->json()['error']);

        $key = self::newKey();
        $first = $this->appPostJson($path, $session, [], $key);
        self::assertSame(428, $this->httpStatus(), 'no confirm token: the server asks for confirmation');
        self::assertStringContainsString(number_format($this->payment->getAmount()), (string) $first['confirm']['message']);
        $this->postWithConfirm($path, $session, [], $key, 'forged.'.str_repeat('x', 43));
        self::assertSame(428, $this->httpStatus(), 'a forged token is not a confirmation');
        $this->em->clear();
        self::assertSame(PaymentStatus::SUBMITTED, $this->em->find(Payment::class, $this->payment->getId())?->getStatus());

        $token = (string) $first['confirm']['token'];
        $answer = $this->postWithConfirm($path, $session, [], $key, $token);
        self::assertSame(200, $this->httpStatus(), json_encode($answer));
        self::assertSame('confirmed', $answer['outcome']);
        $repeat = $this->postWithConfirm($path, $session, [], $key, $token);
        self::assertSame($answer, $repeat, 'a double tap gets the first answer');

        $this->em->clear();
        $payment = $this->em->find(Payment::class, $this->payment->getId());
        self::assertSame(PaymentStatus::CONFIRMED, $payment->getStatus());
        self::assertSame('miniapp_payment_approval', $payment->getConfirmationSource());
        self::assertSame($this->adminUser->getId(), $payment->getConfirmedByAdminId());
        self::assertSame(1, $this->countServicesForOrder($payment->getOrder()));

        $audit = $this->auditRows();
        self::assertCount(1, $audit);
        self::assertSame(['payment.approve', 'miniapp', (string) $this->payment->getId(), $this->adminUser->getId()], [$audit[0]['action'], $audit[0]['source'], $audit[0]['target_id'], (int) $audit[0]['actor_admin_user_id']]);

        // Again with a new key: nothing left to approve.
        $again = $this->confirmedPost($path, $session);
        self::assertSame(409, $this->httpStatus());
        self::assertSame('already_processed', $again['outcome']);
    }

    public function testAProvisioningFailureIsShownMaskedAndCanBeRetried(): void
    {
        $this->driver()->failNext(1, 'Panel https://panel.example:2053/api refused, token=supersecret123');
        $session = $this->authenticate(self::SUPER);
        $answer = $this->confirmedPost('/app/api/admin/payments/'.$this->payment->getId().'/approve', $session);
        self::assertSame(502, $this->httpStatus());
        self::assertSame('provisioning_failed', $answer['outcome']);
        self::assertStringNotContainsString('supersecret123', json_encode($answer, JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('panel.example', json_encode($answer, JSON_THROW_ON_ERROR));

        $page = new Crawler($this->appGet('/app/admin/payments/'.$this->payment->getId(), $session));
        self::assertSame(['retry', 'reject'], $page->filter('[data-action-bar] [data-admin-action]')->each(static fn (Crawler $c): string => (string) $c->attr('data-admin-action')));
        self::assertCount(1, $page->filter('.danger-zone [data-admin-action="reject_refunded"]'));

        $retry = $this->confirmedPost('/app/api/admin/payments/'.$this->payment->getId().'/retry', $session);
        self::assertSame(200, $this->httpStatus(), json_encode($retry));
        self::assertSame(['payment.approve', 'payment.retry'], array_column($this->auditRows(), 'action'));
    }

    public function testRejectAsRefunded(): void
    {
        $this->payment->setStatus(PaymentStatus::NEEDS_REVIEW);
        $this->em->flush();
        $answer = $this->confirmedPost('/app/api/admin/payments/'.$this->payment->getId().'/reject-refunded', $this->authenticate(self::ADMIN));
        self::assertSame(200, $this->httpStatus(), json_encode($answer));
        $this->em->clear();
        $payment = $this->em->find(Payment::class, $this->payment->getId());
        self::assertSame(PaymentStatus::REJECTED, $payment->getStatus());
        self::assertNotNull($payment->getRefundedAt());
        self::assertSame(['outcome' => 'rejected', 'refunded' => true], json_decode((string) $this->auditRows()[0]['details'], true));
    }

    public function testTheReceiptIsServedOnlyToPaymentViewersThroughTheServer(): void
    {
        $this->http->onJson('/getFile', ['ok' => true, 'result' => ['file_id' => 'AgAD-receipt', 'file_size' => strlen(self::JPEG), 'file_path' => 'photos/file_1.jpg']]);
        $this->http->on('/file/bot', static fn (): MockResponse => new MockResponse(self::JPEG, ['response_headers' => ['content-type' => 'application/octet-stream']]));
        $path = '/app/api/admin/payments/'.$this->payment->getId().'/receipt';

        foreach ([self::CUSTOMER_ONLY => 403, self::INACTIVE => 403, self::BUYER => 403] as $who => $status) {
            $this->appGet($path, $this->authenticate((string) $who));
            self::assertSame($status, $this->httpStatus(), (string) $who);
        }
        $this->appGet($path, null);
        self::assertSame(401, $this->httpStatus());

        $support = $this->authenticate(self::SUPPORT);
        $body = $this->appGet($path, $support);
        self::assertSame(200, $this->httpStatus());
        self::assertSame('image/jpeg', $this->client->getResponse()->headers->get('Content-Type'));
        self::assertSame(self::JPEG, $body);
        self::assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('Cache-Control'));

        $this->http->onJson('/getFile', ['ok' => true, 'result' => ['file_id' => 'AgAD-receipt', 'file_size' => 20, 'file_path' => 'docs/evil.html']]);
        $this->http->on('/file/bot', static fn (): MockResponse => new MockResponse('<script>alert(1)</script>'));
        $this->appGet($path, $support);
        self::assertSame(404, $this->httpStatus(), 'only an image is ever served');
    }

    public function testAdminActionsAreRateLimitedPerAdmin(): void
    {
        $session = $this->authenticate(self::ADMIN);
        $limiter = self::getContainer()->get('limiter.miniapp_admin_action')->create('admin:'.self::ADMIN);
        $limiter->consume(30);

        $this->confirmedPost('/app/api/admin/payments/'.$this->payment->getId().'/reject', $session);
        self::assertSame(429, $this->httpStatus());
        self::assertSame('rate_limited', $this->json()['error']);
        $this->em->clear();
        self::assertSame(PaymentStatus::SUBMITTED, $this->em->find(Payment::class, $this->payment->getId())?->getStatus());
    }
}
