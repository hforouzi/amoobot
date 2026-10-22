<?php

declare(strict_types=1);

namespace App\Tests\Admin;

use App\Entity\AdminUser;
use App\Entity\DiscountCode;
use App\Entity\Plan;
use App\Entity\TrialPlan;
use App\Shop\Application\Catalog\DiscountAdminService;
use App\Shop\Application\Catalog\InvalidCatalogEntry;
use App\Shop\Application\Catalog\PlanAdminService;
use App\Shop\Application\Catalog\PlanInUse;
use App\Shop\Application\Catalog\TrialPlanAdminService;
use App\Tests\Support\IntegrationTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\DomCrawler\Field\ChoiceFormField;

/**
 * One rule set for plans, trial plans and discount codes: the web form and the catalog admin services (which the bot,
 * the CLI and the Mini App use) refuse the same input on the same field, and nothing is saved.
 */
final class CatalogRulesTest extends IntegrationTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->em->getConnection()->executeStatement('DELETE FROM admin_audit_log');
        $this->em->getConnection()->executeStatement('DELETE FROM admin_user');
        $admin = (new AdminUser())->setEmail('rules@example.test')->setPassword('unused')->setRoles([AdminUser::ROLE_ADMIN]);
        $this->em->persist($admin);
        $this->em->flush();
        $this->client = new KernelBrowser(self::$kernel);
        $this->client->disableReboot();
        $this->client->loginUser($admin, 'main');
    }

    /**
     * @return iterable<string, array{string, string, array<string, string|bool>, string}>
     */
    public static function invalidInput(): iterable
    {
        $plan = ['title' => 'Rule plan', 'durationDays' => '30', 'trafficGb' => '50', 'price' => '250000'];
        yield 'plan without a name' => ['plan', 'Plan', ['title' => ''] + $plan, 'title'];
        yield 'limited plan of 0 days' => ['plan', 'Plan', ['durationDays' => '0'] + $plan, 'durationDays'];
        yield 'negative price' => ['plan', 'Plan', ['price' => '-5'] + $plan, 'price'];
        yield 'traffic range upside down' => ['plan', 'Plan', ['minTrafficGb' => '50', 'maxTrafficGb' => '10'] + $plan, 'maxTrafficGb'];
        yield 'duration range upside down' => ['plan', 'Plan', ['minDurationDays' => '60', 'maxDurationDays' => '30'] + $plan, 'maxDurationDays'];
        yield 'customizable without unit prices' => ['plan', 'Plan', ['isCustomizable' => true] + $plan, 'pricePerGb'];

        $code = ['code' => 'RULE10', 'type' => 'percent', 'value' => '10', 'appliesTo' => 'all'];
        yield 'percent over 100' => ['discount-code', 'DiscountCode', ['value' => '150'] + $code, 'value'];
        yield 'percent of 0' => ['discount-code', 'DiscountCode', ['value' => '0'] + $code, 'value'];
        yield 'fixed of 0' => ['discount-code', 'DiscountCode', ['type' => 'fixed', 'value' => '0'] + $code, 'value'];
        yield 'code with spaces' => ['discount-code', 'DiscountCode', ['code' => 'BAD CODE'] + $code, 'code'];
        yield 'duplicate code' => ['discount-code', 'DiscountCode', ['code' => 'TAKEN'] + $code, 'code'];
        yield 'end before start' => ['discount-code', 'DiscountCode', ['startsAt' => '2026-05-10T10:00', 'endsAt' => '2026-05-01T10:00'] + $code, 'endsAt'];
        yield 'zero max uses' => ['discount-code', 'DiscountCode', ['maxUses' => '0'] + $code, 'maxUses'];

        $trial = ['title' => 'Rule trial', 'durationDays' => '1', 'trafficGb' => '1', 'maxClaimsPerUser' => '1', 'sortOrder' => '0'];
        yield 'trial without a name' => ['trial-plan', 'TrialPlan', ['title' => ''] + $trial, 'title'];
        // Days and claim limits are clamped by TrialPlan's setters (0 days -> 1), as they always were: no refusal.
        yield 'trial name too long' => ['trial-plan', 'TrialPlan', ['title' => str_repeat('x', 256)] + $trial, 'title'];
    }

    /**
     * @param array<string, string|bool> $fields
     */
    #[DataProvider('invalidInput')]
    public function testTheWebAndTheServicesRefuseTheSameInput(string $crud, string $form, array $fields, string $field): void
    {
        $taken = (new DiscountCode())->setCode('TAKEN')->setType(DiscountCode::TYPE_PERCENT)->setValue(5);
        $this->em->persist($taken);
        $this->em->flush();
        $countBefore = $this->rows($form);

        // The web form: refused, the error on that field, nothing saved.
        $crawler = $this->client->request('GET', '/admin/'.$crud.'/new');
        $webForm = $crawler->filter('form[name="'.$form.'"]')->form();
        foreach ($fields as $name => $value) {
            $input = $webForm[$form.'['.$name.']'];
            if (is_bool($value)) {
                self::assertInstanceOf(ChoiceFormField::class, $input);
                $value ? $input->tick() : $input->untick();
            } else {
                $webForm[$form.'['.$name.']'] = $value;
            }
        }
        $page = $this->client->submit($webForm);
        self::assertNotSame(302, $this->client->getResponse()->getStatusCode(), 'the web saved it');
        self::assertCount(1, $page->filter('#'.$form.'_'.$field.'.is-invalid, [id^="'.$form.'_'.$field.'"].is-invalid'), 'web error on '.$field.': '.$this->webErrors($page));

        // The services: the same field.
        $entity = $this->entity($form, $fields);
        try {
            match ($form) {
                'Plan' => self::getContainer()->get(PlanAdminService::class)->save($entity),
                'TrialPlan' => self::getContainer()->get(TrialPlanAdminService::class)->save($entity),
                default => self::getContainer()->get(DiscountAdminService::class)->save($entity),
            };
            self::fail('the service saved it');
        } catch (InvalidCatalogEntry $e) {
            self::assertArrayHasKey($field, $e->violations, json_encode($e->violations, JSON_UNESCAPED_UNICODE));
        }
        self::assertSame($countBefore, $this->rows($form));
    }

    public function testCodesAreStoredUpperCaseSoCustomersCanRedeemThem(): void
    {
        $crawler = $this->client->request('GET', '/admin/discount-code/new');
        $form = $crawler->filter('form[name="DiscountCode"]')->form();
        $form['DiscountCode[code]'] = ' spring10 ';
        $form['DiscountCode[value]'] = '10';
        $this->client->submit($form);
        self::assertSame(302, $this->client->getResponse()->getStatusCode());
        self::assertInstanceOf(DiscountCode::class, $this->em->getRepository(DiscountCode::class)->findOneBy(['code' => 'SPRING10']));
    }

    public function testAPlanWithOrdersIsNotDeletedAnywhere(): void
    {
        $plan = $this->createPlan();
        $this->createOrder($this->createUser('871001'), $plan);
        $id = (int) $plan->getId();

        try {
            self::getContainer()->get(PlanAdminService::class)->delete($plan);
            self::fail('deleted');
        } catch (PlanInUse $e) {
            self::assertSame(1, $e->orderCount);
        }

        $index = $this->client->request('GET', '/admin/plan');
        $this->client->request('POST', '/admin/plan/'.$id.'/delete', ['token' => (string) $index->filter('input[name="token"]')->attr('value')]);
        self::assertSame(302, $this->client->getResponse()->getStatusCode(), 'a message, not an error page');
        $this->em->clear();
        self::assertNotNull($this->em->find(Plan::class, $id));
        $this->client->followRedirect();
        self::assertStringContainsString('حذف نمی‌شود', (string) $this->client->getResponse()->getContent());
    }

    /**
     * @param array<string, string|bool> $fields
     */
    private function entity(string $form, array $fields): Plan|TrialPlan|DiscountCode
    {
        $int = static fn (string $name): ?int => isset($fields[$name]) && '' !== $fields[$name] ? (int) $fields[$name] : null;
        $date = static fn (string $name): ?\DateTimeImmutable => isset($fields[$name]) ? new \DateTimeImmutable((string) $fields[$name]) : null;

        return match ($form) {
            'Plan' => (new Plan())->setTitle((string) $fields['title'])->setDurationDays((int) $int('durationDays'))->setTrafficGb($int('trafficGb'))
                ->setPrice((int) $int('price'))->setMinTrafficGb($int('minTrafficGb'))->setMaxTrafficGb($int('maxTrafficGb'))
                ->setMinDurationDays($int('minDurationDays'))->setMaxDurationDays($int('maxDurationDays'))->setIsCustomizable(true === ($fields['isCustomizable'] ?? false)),
            'TrialPlan' => (new TrialPlan())->setTitle((string) $fields['title'])->setDurationDays((int) $int('durationDays'))->setTrafficGb($int('trafficGb'))->setMaxClaimsPerUser((int) $int('maxClaimsPerUser')),
            default => (new DiscountCode())->setCode((string) $fields['code'])->setType((string) $fields['type'])->setValue((int) $int('value'))
                ->setAppliesTo((string) $fields['appliesTo'])->setMaxUses($int('maxUses'))->setStartsAt($date('startsAt'))->setEndsAt($date('endsAt')),
        };
    }

    private function rows(string $form): int
    {
        $this->em->clear();

        return $this->em->getRepository(match ($form) {
            'Plan' => Plan::class,
            'TrialPlan' => TrialPlan::class,
            default => DiscountCode::class,
        })->count([]);
    }

    private function webErrors(Crawler $page): string
    {
        return implode(' | ', $page->filter('.invalid-feedback, .form-error-message, .is-invalid')->each(static fn (Crawler $c): string => (string) ($c->attr('id') ?? trim($c->text()))));
    }
}
