<?php

declare(strict_types=1);

namespace App\Tests\MiniApp\Admin;

use App\Entity\Plan;
use App\Tests\Admin\CatalogRulesTest;
use App\Tests\Support\BotAdminTestTrait;
use App\Tests\Support\IntegrationTestCase;
use App\Tests\Support\MiniAppAdminFixtures;
use App\Tests\Support\MiniAppTestTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Plans and the bulk price change in the Mini App admin: the same rules and outcomes as the web form (same cases as
 * CatalogRulesTest), the price confirmation, duplicate, toggle, delete rules, audit (source miniapp), idempotency.
 */
final class MiniAppAdminPlansTest extends IntegrationTestCase
{
    use BotAdminTestTrait;
    use MiniAppAdminFixtures;
    use MiniAppTestTrait;

    private const VALID = ['title' => 'Mini 30', 'durationDays' => '۳۰', 'trafficGb' => '50', 'price' => '۲۵۰٬۰۰۰', 'isActive' => '1', 'allowCustomUsername' => '1'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAdminCast();
        $this->startMiniAppClient();
    }

    /**
     * @param array<string, string|bool> $fields
     */
    #[DataProvider('webCases')]
    public function testTheSameInputIsRefusedOnTheSameFieldAsOnTheWeb(string $crud, string $form, array $fields, string $field): void
    {
        $paths = ['plan' => '/app/api/admin/plans', 'trial-plan' => '/app/api/admin/trial-plans', 'discount-code' => '/app/api/admin/discounts'];
        if ('discount-code' === $crud) {
            $this->appPostJson('/app/api/admin/discounts', $this->authenticate(self::ADMIN), ['code' => 'TAKEN', 'type' => 'percent', 'value' => '5', 'appliesTo' => 'all'], self::newKey());
            self::assertSame(200, $this->httpStatus());
        }
        $body = array_map(static fn (string|bool $v): string => true === $v ? '1' : (false === $v ? '' : $v), $fields);
        $answer = $this->appPostJson($paths[$crud], $this->authenticate(self::ADMIN), $body, self::newKey());

        self::assertSame(422, $this->httpStatus(), json_encode($answer, JSON_UNESCAPED_UNICODE));
        self::assertArrayHasKey($field, $answer['errors'], json_encode($answer['errors'], JSON_UNESCAPED_UNICODE));
        self::assertDoesNotMatchRegularExpression('/^[a-z_]+\.[a-z_.]+$/', implode(' ', $answer['errors'][$field]), 'a translated message, not a key');
    }

    /**
     * @return iterable<string, array{string, string, array<string, string|bool>, string}>
     */
    public static function webCases(): iterable
    {
        foreach (CatalogRulesTest::invalidInput() as $name => $case) {
            if (in_array($case[0], ['plan', 'trial-plan'], true)) {
                yield $name => $case;
            }
        }
    }

    public function testCreateWithPersianDigitsListAndAudit(): void
    {
        $session = $this->authenticate(self::ADMIN);
        $answer = $this->appPostJson('/app/api/admin/plans', $session, self::VALID, self::newKey());
        self::assertSame(200, $this->httpStatus(), json_encode($answer, JSON_UNESCAPED_UNICODE));
        $plan = $this->em->getRepository(Plan::class)->findOneBy(['title' => 'Mini 30']);
        self::assertInstanceOf(Plan::class, $plan);
        self::assertSame([30, 50, 250000, true, false], [$plan->getDurationDays(), $plan->getTrafficGb(), $plan->getPrice(), $plan->isActive(), $plan->isCustomizable()]);
        self::assertSame('/app/admin/plans/'.$plan->getId(), $answer['redirect']);
        self::assertSame(['plan.create', 'miniapp', (string) $plan->getId()], [$this->auditRows()[0]['action'], $this->auditRows()[0]['source'], $this->auditRows()[0]['target_id']]);

        $list = new Crawler($this->appGet('/app/admin/plans?tab=active', $session));
        self::assertSame(200, $this->httpStatus());
        self::assertCount(1, $list->filter('[data-plan-id="'.$plan->getId().'"]'));
        self::assertDoesNotMatchRegularExpression(self::RAW_KEY, strip_tags($list->html()));
        $form = new Crawler($this->appGet('/app/admin/plans/'.$plan->getId().'?lang=en', $session));
        self::assertSame(200, $this->httpStatus());
        self::assertCount(6, $form->filter('details.form-section'));
        self::assertDoesNotMatchRegularExpression(self::RAW_KEY, strip_tags($form->html()));
    }

    public function testAPriceChangeIsConfirmedOldToNewWithTheWarningAndAudited(): void
    {
        $session = $this->authenticate(self::ADMIN);
        $plan = $this->createPlan(150000);
        $path = '/app/api/admin/plans/'.$plan->getId();
        $body = ['title' => $plan->getTitle(), 'durationDays' => '30', 'price' => '1500000', 'isActive' => '1', 'allowCustomUsername' => '1'];

        $key = self::newKey();
        $first = $this->appPostJson($path, $session, $body, $key);
        self::assertSame(428, $this->httpStatus(), json_encode($first, JSON_UNESCAPED_UNICODE));
        self::assertStringContainsString('150,000', $first['confirm']['message']);
        self::assertStringContainsString('1,500,000', $first['confirm']['message']);
        $this->em->clear();
        self::assertSame(150000, $this->em->find(Plan::class, $plan->getId())?->getPrice(), 'nothing saved before the confirmation');

        $this->postWithConfirm($path, $session, $body, $key, (string) $first['confirm']['token']);
        self::assertSame(200, $this->httpStatus());
        $this->em->clear();
        self::assertSame(1500000, $this->em->find(Plan::class, $plan->getId())?->getPrice());
        $audit = json_decode((string) $this->auditRows()[0]['details'], true);
        self::assertSame(['price' => [150000, 1500000]], $audit['values']);
        self::assertContains('price', $audit['changed']);

        // Not a price: saved at once, audited with the field name only.
        $this->appPostJson($path, $session, ['description' => 'Fast'] + $body + ['price' => '1500000'], self::newKey());
        self::assertSame(200, $this->httpStatus());
        self::assertSame(['changed' => ['description']], json_decode((string) $this->auditRows()[1]['details'], true));
    }

    public function testARefusedOrUnconfirmedEditIsNeverSavedByALaterWrite(): void
    {
        $session = $this->authenticate(self::ADMIN);
        $plan = $this->createPlan(150000);
        $this->appPostJson('/app/api/admin/plans/'.$plan->getId(), $session, ['title' => '', 'durationDays' => '30', 'price' => '1'], self::newKey());
        self::assertSame(422, $this->httpStatus());
        $this->appPostJson('/app/api/admin/plans/'.$plan->getId(), $session, ['title' => 'Changed', 'durationDays' => '30', 'price' => '999'], self::newKey());
        self::assertSame(428, $this->httpStatus());
        $this->confirmedPost('/app/api/admin/plans/'.$plan->getId().'/deactivate', $session);
        self::assertSame(200, $this->httpStatus());

        $this->em->clear();
        $saved = $this->em->find(Plan::class, $plan->getId());
        self::assertSame([$plan->getTitle(), 150000, false], [$saved?->getTitle(), $saved?->getPrice(), $saved?->isActive()]);
    }

    public function testLiveCheckGivesErrorsAndWarningsAndSavesNothing(): void
    {
        $session = $this->authenticate(self::ADMIN);
        $plan = $this->createPlan(150000);
        $answer = $this->appPostJson('/app/api/admin/plans/check', $session, ['id' => (string) $plan->getId(), 'title' => $plan->getTitle(), 'durationDays' => '30', 'price' => '50']);
        self::assertSame(200, $this->httpStatus());
        self::assertSame([], (array) $answer['errors']);
        self::assertCount(1, $answer['warnings'], 'price 50 looks too low');

        $answer = $this->appPostJson('/app/api/admin/plans/check', $session, ['id' => (string) $plan->getId(), 'title' => '', 'durationDays' => 'x', 'price' => '50']);
        self::assertArrayHasKey('durationDays', $answer['errors']);
        $this->em->clear();
        self::assertSame(150000, $this->em->find(Plan::class, $plan->getId())?->getPrice());
    }

    public function testDuplicateToggleAndTheDeleteRule(): void
    {
        $session = $this->authenticate(self::SUPER);
        $plan = $this->createPlan(150000)->setPricePerGb(4000)->setIsCustomizable(true);
        $this->em->flush();

        $answer = $this->appPostJson('/app/api/admin/plans/'.$plan->getId().'/duplicate', $session, [], self::newKey());
        self::assertSame(200, $this->httpStatus(), json_encode($answer, JSON_UNESCAPED_UNICODE));
        $copyId = (int) basename((string) $answer['redirect']);
        $copy = $this->em->find(Plan::class, $copyId);
        self::assertSame([false, 150000, 4000, true], [$copy?->isActive(), $copy?->getPrice(), $copy?->getPricePerGb(), $copy?->isCustomizable()]);
        self::assertStringContainsString($plan->getTitle(), (string) $copy?->getTitle());

        $this->confirmedPost('/app/api/admin/plans/'.$copyId.'/activate', $session);
        self::assertSame(200, $this->httpStatus());
        $again = $this->confirmedPost('/app/api/admin/plans/'.$copyId.'/activate', $session);
        self::assertSame(409, $this->httpStatus(), json_encode($again));

        // The fixture plan has an order: refused, as on the web.
        $used = $this->payment->getOrder()->getPlan();
        $refused = $this->confirmedPost('/app/api/admin/plans/'.$used->getId().'/delete', $session);
        self::assertSame(409, $this->httpStatus());
        self::assertSame('plan_in_use', $refused['error']);
        self::assertStringContainsString('data-delete-blocked', $this->appGet('/app/admin/plans/'.$used->getId(), $session));

        $this->confirmedPost('/app/api/admin/plans/'.$copyId.'/delete', $session);
        self::assertSame(200, $this->httpStatus());
        $this->em->clear();
        self::assertNull($this->em->find(Plan::class, $copyId));
        self::assertSame(['plan.duplicate', 'plan.activate', 'plan.delete'], array_column($this->auditRows(), 'action'));
    }

    public function testADoubleSubmitCreatesOnePlan(): void
    {
        $session = $this->authenticate(self::ADMIN);
        $key = self::newKey();
        $first = $this->appPostJson('/app/api/admin/plans', $session, self::VALID, $key);
        $second = $this->appPostJson('/app/api/admin/plans', $session, self::VALID, $key);
        self::assertSame($first, $second);
        self::assertSame(1, $this->em->getRepository(Plan::class)->count(['title' => 'Mini 30']));

        $this->appPostJson('/app/api/admin/plans', $session, self::VALID, self::newKey(), 'bad-csrf');
        self::assertSame(403, $this->httpStatus());
        $this->appPostJson('/app/api/admin/plans', $session, self::VALID);
        self::assertSame(400, $this->httpStatus(), 'no Idempotency-Key');
    }

    public function testBulkPreviewMatchesWhatIsApplied(): void
    {
        $session = $this->authenticate(self::ADMIN);
        $existing = $this->payment->getOrder()->getPlan();
        $a = $this->createPlan(99999);
        $b = $this->createPlan(200000)->setIsActive(false);
        $this->em->flush();
        $activeCount = $this->em->getRepository(Plan::class)->count(['isActive' => true]);

        $preview = $this->appPostJson('/app/api/admin/plans/bulk/preview', $session, ['percent' => '+۱۰', 'field' => 'price', 'scope' => 'active']);
        self::assertSame(200, $this->httpStatus(), json_encode($preview, JSON_UNESCAPED_UNICODE));
        self::assertSame([$activeCount, $activeCount], [$preview['plans'], $preview['changes']]);
        self::assertStringContainsString('99,999', $preview['html']);
        self::assertStringContainsString('109,998', $preview['html']);

        $selection = $this->appPostJson('/app/api/admin/plans/bulk/preview', $session, ['percent' => '-5', 'field' => 'price', 'scope' => 'selection', 'plans' => [(string) $b->getId()]]);
        self::assertSame(1, $selection['plans'], 'a selection may include an inactive plan');

        foreach ([['percent' => '0'], ['percent' => '500'], ['percent' => 'abc'], ['percent' => '10', 'scope' => 'selection']] as $bad) {
            $this->appPostJson('/app/api/admin/plans/bulk/preview', $session, $bad + ['field' => 'price', 'scope' => 'active']);
            self::assertSame(422, $this->httpStatus(), json_encode($bad));
        }

        $applied = $this->confirmedPost('/app/api/admin/plans/bulk/apply', $session, ['percent' => '+۱۰', 'field' => 'price', 'scope' => 'active']);
        self::assertSame(200, $this->httpStatus(), json_encode($applied, JSON_UNESCAPED_UNICODE));
        $this->em->clear();
        self::assertSame(109998, $this->em->find(Plan::class, $a->getId())?->getPrice());
        self::assertSame(200000, $this->em->find(Plan::class, $b->getId())?->getPrice(), 'inactive plans are outside "all active plans"');
        self::assertSame((int) floor($existing->getPrice() * 1.1), $this->em->find(Plan::class, $existing->getId())?->getPrice());
        $audit = $this->auditRows()[0];
        self::assertSame(['plan.bulk_price_adjust', 'miniapp'], [$audit['action'], $audit['source']]);
        self::assertSame(['direction' => 'increase', 'field' => 'price', 'percent' => 10.0, 'scope' => 'active', 'plans' => $activeCount, 'applied' => $activeCount], json_decode((string) $audit['details'], true));
    }

    public function testSupportSeesNoCatalogAtAll(): void
    {
        $session = $this->authenticate(self::SUPPORT);
        $admin = new Crawler($this->appGet('/app/admin', $session));
        self::assertCount(0, $admin->filter('[data-area="admin.plans"]'));
        foreach (['/app/admin/plans', '/app/admin/plans/bulk', '/app/admin/trial-plans'] as $path) {
            $this->appGet($path, $session);
            self::assertSame(403, $this->httpStatus(), $path);
        }
        $this->appPostJson('/app/api/admin/plans', $session, self::VALID, self::newKey());
        self::assertSame(403, $this->httpStatus());
        self::assertSame(0, $this->em->getRepository(Plan::class)->count(['title' => 'Mini 30']));
    }
}
