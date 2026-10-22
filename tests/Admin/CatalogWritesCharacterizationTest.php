<?php

declare(strict_types=1);

namespace App\Tests\Admin;

use App\Entity\AdminUser;
use App\Entity\DiscountCode;
use App\Entity\Plan;
use App\Entity\TrialPlan;
use App\Tests\Support\IntegrationTestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\DomCrawler\Field\ChoiceFormField;

/**
 * What the web panel and the CLI save for plans, trial plans and discount codes, pinned before their rules move into
 * the catalog admin services (PlanAdminService, TrialPlanAdminService, DiscountAdminService).
 */
final class CatalogWritesCharacterizationTest extends IntegrationTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->em->getConnection()->executeStatement('DELETE FROM admin_audit_log');
        $this->em->getConnection()->executeStatement('DELETE FROM admin_user');
        $admin = (new AdminUser())->setEmail('catalog@example.test')->setPassword('unused')->setRoles([AdminUser::ROLE_ADMIN]);
        $this->em->persist($admin);
        $this->em->flush();
        $this->client = new KernelBrowser(self::$kernel);
        $this->client->disableReboot();
        $this->client->loginUser($admin, 'main');
    }

    public function testThePlanFormCreatesEditsAndDeletesAPlan(): void
    {
        $this->submit('/admin/plan/new', 'Plan', [
            'title' => 'Gold 30', 'durationDays' => '30', 'trafficGb' => '50', 'price' => '250000', 'isActive' => true,
        ]);
        $plan = $this->em->getRepository(Plan::class)->findOneBy(['title' => 'Gold 30']);
        self::assertInstanceOf(Plan::class, $plan);
        self::assertSame([30, 50, 250000, true], [$plan->getDurationDays(), $plan->getTrafficGb(), $plan->getPrice(), $plan->isActive()]);
        $id = (int) $plan->getId();

        $this->submit('/admin/plan/'.$id.'/edit', 'Plan', ['price' => '260000']);
        $this->em->clear();
        self::assertSame(260000, $this->em->find(Plan::class, $id)?->getPrice());

        $this->delete('/admin/plan/'.$id.'/delete');
        $this->em->clear();
        self::assertNull($this->em->find(Plan::class, $id));
    }

    public function testTheDiscountFormCreatesAndDeletesACode(): void
    {
        $this->submit('/admin/discount-code/new', 'DiscountCode', ['code' => 'SPRING10', 'type' => 'percent', 'value' => '10', 'appliesTo' => 'all']);
        $code = $this->em->getRepository(DiscountCode::class)->findOneBy(['code' => 'SPRING10']);
        self::assertInstanceOf(DiscountCode::class, $code);
        self::assertSame(['percent', 10, true], [$code->getType(), $code->getValue(), $code->isActive()]);

        $this->delete('/admin/discount-code/'.$code->getId().'/delete');
        $this->em->clear();
        self::assertNull($this->em->getRepository(DiscountCode::class)->findOneBy(['code' => 'SPRING10']));
    }

    public function testTheTrialPlanFormCreatesATrialPlan(): void
    {
        $this->submit('/admin/trial-plan/new', 'TrialPlan', ['title' => 'Try 1 day', 'durationDays' => '1', 'trafficGb' => '1', 'maxClaimsPerUser' => '1', 'sortOrder' => '0']);
        $trial = $this->em->getRepository(TrialPlan::class)->findOneBy(['title' => 'Try 1 day']);
        self::assertInstanceOf(TrialPlan::class, $trial);
        self::assertSame([1, 1, 1], [$trial->getDurationDays(), $trial->getTrafficGb(), $trial->getMaxClaimsPerUser()]);
    }

    public function testTheCliCreatesACodeAndRefusesBadInput(): void
    {
        $command = (new Application(self::$kernel))->find('app:discount:create');
        $tester = new CommandTester($command);

        self::assertSame(0, $tester->execute(['--code' => 'cli5', '--type' => 'percent', '--value' => '5', '--max-uses' => '3', '--days-valid' => '7']));
        $code = $this->em->getRepository(DiscountCode::class)->findOneBy(['code' => 'CLI5']);
        self::assertInstanceOf(DiscountCode::class, $code);
        self::assertSame(3, $code->getMaxUses());
        self::assertNotNull($code->getEndsAt());

        self::assertSame(1, $tester->execute(['--code' => 'CLI5', '--type' => 'percent', '--value' => '5']), 'duplicate');
        self::assertSame(1, $tester->execute(['--code' => 'BAD', '--type' => 'percent', '--value' => '150']), 'percent over 100');
        self::assertSame(1, $tester->execute(['--code' => 'BAD', '--type' => 'fixed', '--value' => '0']), 'fixed not positive');
    }

    /**
     * @param array<string, string|bool> $fields
     */
    private function submit(string $path, string $formName, array $fields): void
    {
        $crawler = $this->client->request('GET', $path);
        self::assertSame(200, $this->client->getResponse()->getStatusCode(), $path);
        $form = $crawler->filter('form[name="'.$formName.'"]')->form();
        foreach ($fields as $name => $value) {
            if (is_bool($value)) {
                $box = $form[$formName.'['.$name.']'];
                self::assertInstanceOf(ChoiceFormField::class, $box);
                $value ? $box->tick() : $box->untick();
            } else {
                $form[$formName.'['.$name.']'] = $value;
            }
        }
        $this->client->submit($form);
        self::assertSame(302, $this->client->getResponse()->getStatusCode(), $path.': '.$this->errors());
    }

    private function delete(string $path): void
    {
        $index = $this->client->request('GET', preg_replace('#/\d+/delete$#', '', $path) ?? $path);
        $token = $index->filter('input[name="token"]');
        $this->client->request('POST', $path, ['token' => $token->count() > 0 ? (string) $token->attr('value') : '']);
        self::assertContains($this->client->getResponse()->getStatusCode(), [302, 303], $path);
    }

    private function errors(): string
    {
        $crawler = new Crawler((string) $this->client->getResponse()->getContent());

        return implode(' | ', $crawler->filter('.invalid-feedback, .form-error-message')->each(static fn (Crawler $c): string => trim($c->text())));
    }
}
