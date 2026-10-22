<?php

declare(strict_types=1);

namespace App\Tests\MiniApp\Admin;

use App\Entity\DiscountCode;
use App\Entity\DiscountUsage;
use App\Tests\Support\BotAdminTestTrait;
use App\Tests\Support\IntegrationTestCase;
use App\Tests\Support\MiniAppAdminFixtures;
use App\Tests\Support\MiniAppTestTrait;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Discount codes in the Mini App admin: status chips, live validation, the random code, toggle, the delete rule for
 * used codes, the usage list, audit (source miniapp).
 */
final class MiniAppAdminDiscountsTest extends IntegrationTestCase
{
    use BotAdminTestTrait;
    use MiniAppAdminFixtures;
    use MiniAppTestTrait;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAdminCast();
        $this->startMiniAppClient();
    }

    public function testStatusChipsAndSearch(): void
    {
        $this->code('LIVE10');
        $this->code('OFF10')->setIsActive(false);
        $this->code('OLD10')->setEndsAt(new \DateTimeImmutable('-1 day'));
        $this->code('FULL10')->setMaxUses(2)->setUsedCount(2);
        $this->em->flush();
        $session = $this->authenticate(self::ADMIN);
        $codes = fn (string $query): array => (new Crawler($this->appGet('/app/admin/discounts'.$query, $session)))->filter('[data-discount-id] .code')->each(static fn (Crawler $c): string => trim($c->text()));

        self::assertSame(['LIVE10'], $codes('?tab=active'));
        self::assertSame(['OFF10'], $codes('?tab=inactive'));
        self::assertSame(['OLD10'], $codes('?tab=expired'));
        self::assertSame(['FULL10'], $codes('?tab=exhausted'));
        self::assertSame(['FULL10'], $codes('?q=full'));
        self::assertDoesNotMatchRegularExpression(self::RAW_KEY, strip_tags($this->appGet('/app/admin/discounts', $session)));
    }

    public function testCreateWithLiveValidationAndARandomCode(): void
    {
        $session = $this->authenticate(self::ADMIN);
        $this->code('TAKEN');
        $this->em->flush();

        $check = $this->appPostJson('/app/api/admin/discounts/check', $session, ['code' => 'taken', 'type' => 'percent', 'value' => '150', 'appliesTo' => 'all', 'startsAt' => '2026-06-02T10:00', 'endsAt' => '2026-06-01T10:00']);
        self::assertSame(200, $this->httpStatus());
        self::assertSame(['code', 'value', 'endsAt'], array_keys((array) $check['errors']), 'duplicate (case-insensitive), percent range, dates in order');

        $this->appGet('/app/api/admin/discounts/generate-code', $session);
        $generated = (string) $this->json()['code'];
        self::assertMatchesRegularExpression('/^[A-Z2-9]{8}$/', $generated);

        $answer = $this->appPostJson('/app/api/admin/discounts', $session, ['code' => $generated, 'type' => 'fixed', 'value' => '۲۰۰۰۰', 'appliesTo' => 'renewal', 'maxUses' => '10', 'isActive' => '1'], self::newKey());
        self::assertSame(200, $this->httpStatus(), json_encode($answer, JSON_UNESCAPED_UNICODE));
        $code = $this->em->getRepository(DiscountCode::class)->findOneBy(['code' => $generated]);
        self::assertSame(['fixed', 20000, 'renewal', 10], [$code?->getType(), $code?->getValue(), $code?->getAppliesTo(), $code?->getMaxUses()]);
        self::assertSame('discount_code.create', $this->auditRows()[0]['action']);

        $form = new Crawler($this->appGet('/app/admin/discounts/'.$code?->getId().'?lang=en', $session));
        self::assertSame(200, $this->httpStatus());
        self::assertDoesNotMatchRegularExpression(self::RAW_KEY, strip_tags($form->html()));
        self::assertCount(1, $form->filter('[data-fill-from]'));
    }

    public function testAUsedCodeIsDeactivatedNotDeleted(): void
    {
        $session = $this->authenticate(self::SUPER);
        $used = $this->code('USED10');
        $fresh = $this->code('FRESH10');
        $this->em->persist((new DiscountUsage())->setDiscountCode($used)->setUser($this->buyer)->setOrder($this->payment->getOrder())->setAmountBefore(100000)->setDiscountAmount(10000)->setAmountAfter(90000));
        $this->em->flush();

        self::assertStringContainsString('data-delete-blocked', $this->appGet('/app/admin/discounts/'.$used->getId(), $session));
        $refused = $this->appPostJson('/app/api/admin/discounts/'.$used->getId().'/delete', $session, [], self::newKey());
        self::assertSame(409, $this->httpStatus());
        self::assertSame('discount_used', $refused['error']);

        $usages = new Crawler($this->appGet('/app/admin/discounts/'.$used->getId().'/usages', $session));
        self::assertCount(1, $usages->filter('[data-usage-id]'));

        $this->confirmedPost('/app/api/admin/discounts/'.$used->getId().'/deactivate', $session);
        self::assertSame(200, $this->httpStatus());
        $this->confirmedPost('/app/api/admin/discounts/'.$fresh->getId().'/delete', $session);
        self::assertSame(200, $this->httpStatus());
        $this->em->clear();
        self::assertFalse($this->em->find(DiscountCode::class, $used->getId())?->isActive());
        self::assertNull($this->em->find(DiscountCode::class, $fresh->getId()));
        self::assertSame(['discount_code.deactivate', 'discount_code.delete'], array_column($this->auditRows(), 'action'));
        self::assertSame(['miniapp'], array_values(array_unique(array_column($this->auditRows(), 'source'))));
    }

    public function testEditIsAuditedWithChangedFieldsAndTheValue(): void
    {
        $session = $this->authenticate(self::ADMIN);
        $code = $this->code('EDIT10');
        $this->em->flush();
        $this->appPostJson('/app/api/admin/discounts/'.$code->getId(), $session, ['code' => 'EDIT10', 'type' => 'percent', 'value' => '15', 'appliesTo' => 'all', 'title' => 'Spring', 'isActive' => '1'], self::newKey());
        self::assertSame(200, $this->httpStatus());
        self::assertSame(['changed' => ['title', 'value'], 'values' => ['value' => [10, 15]]], json_decode((string) $this->auditRows()[0]['details'], true));
    }

    private function code(string $code): DiscountCode
    {
        $discount = (new DiscountCode())->setCode($code)->setType(DiscountCode::TYPE_PERCENT)->setValue(10);
        $this->em->persist($discount);

        return $discount;
    }
}
