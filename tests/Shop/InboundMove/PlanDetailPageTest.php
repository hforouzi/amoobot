<?php

declare(strict_types=1);

namespace App\Tests\Shop\InboundMove;

use App\Entity\AdminUser;
use App\Shop\Application\InboundMoveSettings;
use App\Shop\Application\MultiInboundSettings;
use App\Tests\Support\IntegrationTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;

/**
 * The plan's detail page is reachable from the admin UI, and with it the block "Moving existing services" and its link
 * to "Apply to existing services" (docs/design/plan-inbound-change.md 5.6, PR P7): a Show action on the list and on the
 * edit page, an Edit action on the detail page, and an Apply button on the edit page. Every plan type renders. A trial's
 * hidden backing plan stays out of the list and locked: tests/Shop/TrialBacking.
 */
final class PlanDetailPageTest extends IntegrationTestCase
{
    use InboundMoveFixtures;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpMove();
        $this->client = $this->webAdmin(AdminUser::ROLE_SUPER_ADMIN);
    }

    public function testTheListLinksToTheDetailPageAndTheDetailAndEditPagesToEachOther(): void
    {
        $plan = (int) $this->gold->getId();

        $list = $this->get('/admin/plan');
        self::assertCount(1, $list->filter(sprintf('a[href$="/admin/plan/%d"]', $plan)), 'Show on the list');

        $detail = $this->get('/admin/plan/'.$plan);
        self::assertCount(1, $detail->filter(sprintf('a[href$="/admin/plan/%d/edit"]', $plan)), 'Edit on the detail page');

        $edit = $this->get('/admin/plan/'.$plan.'/edit');
        self::assertCount(1, $edit->filter(sprintf('a[href$="/admin/plan/%d"]', $plan)), 'Show on the edit page');
    }

    public function testTheEditPageLinksToTheApplyPage(): void
    {
        $plan = (int) $this->gold->getId();

        $link = $this->get('/admin/plan/'.$plan.'/edit')->filter(sprintf('a[href$="/admin/plan/%d/apply-inbounds"]', $plan));

        self::assertCount(1, $link);
        self::assertStringContainsString('Apply to existing services', $link->text());
        $this->get((string) $link->attr('href'));
    }

    public function testTheDetailPageHasTheMoveBlockAndItsApplyLink(): void
    {
        $plan = (int) $this->gold->getId();

        $detail = $this->get('/admin/plan/'.$plan);

        self::assertCount(1, $detail->filter('#inbound-move'));
        self::assertStringEndsWith('/admin/plan/'.$plan.'/apply-inbounds', (string) $detail->filter('[data-apply-link]')->attr('href'));
    }

    #[DataProvider('planTypes')]
    public function testEveryPlanTypeRendersTheDetailPageWithTheMoveBlock(string $type, bool $multiInbound): void
    {
        $this->setting(MultiInboundSettings::ENABLED, $multiInbound ? '1' : '0', 'boolean');
        $plan = $this->createPlan(100000, $this->nl);
        match ($type) {
            'inactive' => $plan->setIsActive(false),
            'customizable' => $plan->setIsCustomizable(true)->setMinTrafficGb(1)->setMaxTrafficGb(100)->setPricePerGb(100)->setMinDurationDays(1)->setMaxDurationDays(90)->setPricePerDay(100),
            'unlimited' => $plan->setIsUnlimitedDuration(true),
            'no inbound' => $plan->setInbound(null),
            'additional' => $plan->setAdditionalInbounds([$this->de]),
            default => $plan,
        };
        $this->em->flush();

        $detail = $this->get('/admin/plan/'.$plan->getId());

        self::assertCount(1, $detail->filter('#inbound-move'));
        self::assertCount(1, $detail->filter('[data-no-run]'));
    }

    /** @return iterable<string, array{string, bool}> */
    public static function planTypes(): iterable
    {
        yield 'plain' => ['plain', false];
        yield 'inactive' => ['inactive', false];
        yield 'customizable' => ['customizable', false];
        yield 'unlimited duration' => ['unlimited', false];
        yield 'no inbound' => ['no inbound', false];
        yield 'multi-inbound on, plain' => ['plain', true];
        yield 'multi-inbound on, no inbound' => ['no inbound', true];
        yield 'multi-inbound on, an additional inbound' => ['additional', true];
    }

    public function testThePagesKeepTheirMoveSwitchRulesWithTheSwitchOff(): void
    {
        $this->setting(InboundMoveSettings::ENABLED, '0', 'boolean');
        $plan = (int) $this->gold->getId();

        self::assertCount(1, $this->get('/admin/plan/'.$plan)->filter('[data-apply-link]'));
        self::assertCount(1, $this->get('/admin/plan/'.$plan.'/edit')->filter(sprintf('a[href$="/admin/plan/%d/apply-inbounds"]', $plan)));
    }

    public function testTheButtonsNeedThePlanPermission(): void
    {
        $plan = (int) $this->gold->getId();
        $support = $this->webAdmin(AdminUser::ROLE_SUPPORT);

        foreach (['/admin/plan', '/admin/plan/'.$plan, '/admin/plan/'.$plan.'/edit'] as $path) {
            $support->request('GET', $path);
            self::assertSame(403, $support->getResponse()->getStatusCode(), $path);
            self::assertStringNotContainsString('/apply-inbounds', (string) $support->getResponse()->getContent());
        }
    }

    private function get(string $path): Crawler
    {
        $crawler = $this->client->request('GET', $path);
        self::assertSame(200, $this->client->getResponse()->getStatusCode(), $path.': '.mb_substr(strip_tags((string) $this->client->getResponse()->getContent()), 0, 1500));

        return $crawler;
    }

    private function webAdmin(string $role): KernelBrowser
    {
        $admin = (new AdminUser())->setEmail('plan-detail-'.bin2hex(random_bytes(4)).'@example.test')->setPassword('unused')->setRoles([$role]);
        $this->em->persist($admin);
        $this->em->flush();
        $client = new KernelBrowser(self::$kernel);
        $client->disableReboot();
        $client->loginUser($admin, 'main');
        $client->request('GET', '/admin/locale/en');

        return $client;
    }
}
