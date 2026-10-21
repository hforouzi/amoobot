<?php

declare(strict_types=1);

namespace App\Tests\Shop;

use App\Entity\AdminUser;
use App\Entity\Plan;
use App\Shop\Application\PlanPriceSanityChecker;
use App\Tests\Support\IntegrationTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

final class PlanPriceSanityTest extends IntegrationTestCase
{
    /**
     * @return iterable<string, array{int, ?int, ?int, int, string}>
     */
    public static function cases(): iterable
    {
        yield 'typical toman price' => [150_000, null, null, 0, ''];
        yield 'free plan' => [0, null, null, 0, ''];
        yield 'price entered in rial' => [25_000_000, null, null, 1, 'too high'];
        yield 'price in thousands of toman' => [150, null, null, 1, 'too low'];
        yield 'per-GB price entered in rial' => [30_000, 300_000, null, 1, 'Price per GB'];
        yield 'per-day price too low' => [30_000, null, 50, 1, 'Price per day'];
        yield 'edges are plausible' => [PlanPriceSanityChecker::PRICE_RANGE[0], PlanPriceSanityChecker::PRICE_PER_GB_RANGE[1], PlanPriceSanityChecker::PRICE_PER_DAY_RANGE[0], 0, ''];
    }

    #[DataProvider('cases')]
    public function testWarnings(int $price, ?int $perGb, ?int $perDay, int $count, string $contains): void
    {
        $plan = (new Plan())->setTitle('p')->setDurationDays(30)->setTrafficGb(10)->setPrice($price)->setPricePerGb($perGb)->setPricePerDay($perDay);

        $warnings = (new PlanPriceSanityChecker())->warnings($plan);

        self::assertCount($count, $warnings, implode(' | ', $warnings));
        if ('' !== $contains) {
            self::assertStringContainsString($contains, $warnings[0]);
        }
    }

    private function adminClient(): KernelBrowser
    {
        $this->em->getConnection()->executeStatement('DELETE FROM admin_user');
        $admin = (new AdminUser())->setEmail('admin@shop.test')->setPassword('x')->setRoles(['ROLE_SUPER_ADMIN'])->setIsActive(true);
        $this->em->persist($admin);
        $this->em->flush();
        $client = new KernelBrowser(self::$kernel);
        $client->disableReboot();
        $client->loginUser($admin, 'main');

        return $client;
    }

    public function testPlanListShowsTheWarningToAdmins(): void
    {
        $this->createPlan(150_000);
        $this->createPlan(25_000_000);
        $client = $this->adminClient();

        $client->request('GET', '/admin/plan');

        $content = (string) $client->getResponse()->getContent();
        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertSame(1, substr_count($content, 'data-unit-warning'), 'only the Rial-looking plan is flagged');
        self::assertStringContainsString('looks too high', $content);
    }

    public function testSavingAnImplausiblePriceShowsAWarningButStillSaves(): void
    {
        $plan = $this->createPlan(150_000);
        $client = $this->adminClient();

        $crawler = $client->request('GET', sprintf('/admin/plan/%d/edit', $plan->getId()));
        $form = $crawler->filter('form[name="Plan"]')->form();
        $form['Plan[price]'] = '25000000';
        $client->submit($form);
        $client->followRedirect();

        self::assertSame(25_000_000, (int) $this->row('plan', (int) $plan->getId())['price']);
        // The flash (admin's language, Persian by default) names the plan and carries the checker's warning.
        self::assertStringContainsString('پلن «Test plan»: Price 25,000,000 IRT looks too high', (string) $client->getResponse()->getContent());
    }
}
