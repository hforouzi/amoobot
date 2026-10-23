<?php

declare(strict_types=1);

namespace App\Tests\Wallet;

use App\Entity\Order;
use App\MiniApp\Admin\AdminLists;
use App\Shop\Domain\OrderStatus;
use App\Shop\Domain\OrderType;
use App\Tests\Support\BotAdminTestTrait;
use App\Tests\Support\IntegrationTestCase;
use App\Tests\Support\MiniAppAdminFixtures;
use App\Tests\Support\MiniAppTestTrait;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Review finding I4: a wallet top-up order has no plan, and the Mini App admin order list inner-joined the plan, so
 * every top-up was missing from it. The order lists (Mini App and web) show top-ups beside service orders.
 */
final class WalletTopUpOrderListsTest extends IntegrationTestCase
{
    use BotAdminTestTrait;
    use MiniAppAdminFixtures;
    use MiniAppTestTrait;

    private Order $topUp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAdminCast();
        $this->startMiniAppClient();
        $this->topUp = (new Order())->setUser($this->buyer)->setType(OrderType::WALLET_TOPUP)->setAmount(50000)->setStatus(OrderStatus::WAITING_PAYMENT)->setTrackingCode('TRKTOPUP01');
        $this->em->persist($this->topUp);
        $this->em->flush();
    }

    public function testTheMiniAppAdminOrderListIncludesTopUps(): void
    {
        $lists = self::getContainer()->get(AdminLists::class);
        $ids = static fn (array $page): array => array_map(static fn (Order $o): ?int => $o->getId(), $page['items']);

        self::assertContains($this->topUp->getId(), $ids($lists->orders(null, null, 0)));
        self::assertContains($this->topUp->getId(), $ids($lists->orders(OrderStatus::WAITING_PAYMENT, null, 0)));
        self::assertContains($this->topUp->getId(), $ids($lists->orders(null, 'TRKTOPUP', 0)));
        self::assertContains($this->payment->getOrder()->getId(), $ids($lists->orders(null, null, 0)), 'service orders still listed');
    }

    public function testTheMiniAppAdminOrderPagesRenderATopUp(): void
    {
        $session = $this->authenticate(self::ADMIN);

        $list = new Crawler($this->appGet('/app/admin/orders', $session));
        self::assertSame(200, $this->httpStatus());
        self::assertCount(1, $list->filter('[data-order-id="'.$this->topUp->getId().'"]'));

        $this->appGet('/app/admin/orders/'.$this->topUp->getId(), $session);
        self::assertSame(200, $this->httpStatus());
    }

    public function testTheWebAdminOrderListIncludesTopUps(): void
    {
        $this->client->loginUser($this->adminUser, 'main');

        $page = $this->client->request('GET', '/admin/order');

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertCount(1, $page->filter('[data-id="'.$this->topUp->getId().'"]'));
        self::assertCount(1, $page->filter('[data-id="'.$this->payment->getOrder()->getId().'"]'));
    }
}
