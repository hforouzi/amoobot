<?php

declare(strict_types=1);

namespace App\Tests\Admin;

use App\Entity\AdminUser;
use App\Tests\Support\IntegrationTestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;

/**
 * PM2a: the admin shows a plan's and a service's product_type read only, on their detail pages ("Type"), and offers
 * no way to change it (no form field). The list columns stay as pinned in
 * AdminUiPolishTest.
 */
final class ProductTypeAdminTest extends IntegrationTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->em->getConnection()->executeStatement('DELETE FROM admin_user');
        $admin = (new AdminUser())->setEmail('pm2a@shop.test')->setPassword('unused')->setRoles([AdminUser::ROLE_SUPER_ADMIN])->setIsActive(true);
        $this->em->persist($admin);
        $this->em->flush();
        $this->client = new KernelBrowser(self::$kernel);
        $this->client->disableReboot();
        $this->client->loginUser($admin, 'main');
    }

    public function testTheDetailPagesShowTheTypeAndTheFormsDoNotOfferIt(): void
    {
        $plan = $this->createPlan(150000, $this->createTestInbound());
        $service = $this->createActiveService($this->createUser(), $plan->getInbound());

        foreach (['/admin/plan/'.$plan->getId(), '/admin/vpn-service/'.$service->getId()] as $url) {
            $crawler = $this->client->request('GET', $url);
            self::assertSame(200, $this->client->getResponse()->getStatusCode(), $url);
            self::assertSame(['vpn'], $this->typeValues($crawler), $url);
        }
        foreach (['/admin/plan/'.$plan->getId().'/edit', '/admin/vpn-service/'.$service->getId().'/edit', '/admin/plan/new'] as $url) {
            $crawler = $this->client->request('GET', $url);
            self::assertSame(200, $this->client->getResponse()->getStatusCode(), $url);
            self::assertCount(0, $crawler->filter('[name$="[productType]"]'), $url.': no field for it');
        }
    }

    /** @return list<string> the values shown under the product type field of a detail page */
    private function typeValues(Crawler $crawler): array
    {
        return $crawler->filter('.field-group')->reduce(static fn (Crawler $group): bool => str_contains((string) $group->attr('class'), 'field-text') && 1 === $group->filter('[data-column="productType"], .field-label:contains("Type"), .field-label:contains("نوع")')->count())
            ->each(static fn (Crawler $group): string => trim($group->filter('.field-value')->text('')));
    }
}
