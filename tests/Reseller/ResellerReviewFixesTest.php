<?php

declare(strict_types=1);

namespace App\Tests\Reseller;

use App\Admin\UI\ResellerAdminController;
use App\Entity\AdminUser;
use App\Entity\Order;
use App\Entity\Reseller;
use App\Reseller\Application\ResellerCheckout;
use App\Reseller\Domain\ResellerConfirmation;
use App\Reseller\UI\ResellerBotHandler;
use App\Shop\Domain\OrderStatus;
use App\Shop\Domain\OrderType;
use App\Tests\Support\BotAdminTestTrait;
use App\Tests\Support\IntegrationTestCase;
use App\Tests\Support\MiniAppTestTrait;
use App\Tests\Support\QueryCounter;
use App\Tests\Support\ResellerFixtures;
use App\Wallet\Application\WalletPayment;
use App\Wallet\Application\WalletReconciler;
use App\Wallet\Application\WalletSettings;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * S1 review items S1-S5: the admin plan list and reseller list keep a flat query count however many resellers there
 * are; the reseller's message after a failure says what really happened to the money; the reseller button only while
 * the wallet can pay; CSV cells starting with TAB / CR are neutralized; reconcile reports a reseller order that is not
 * on the reseller's own account.
 */
final class ResellerReviewFixesTest extends IntegrationTestCase
{
    use BotAdminTestTrait;
    use MiniAppTestTrait;
    use ResellerFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpReseller();
        $this->fund(1000000);
    }

    /** S1 + S3: queries of the plan list and the reseller list do not grow with the number of resellers. */
    public function testTheAdminListsKeepAFlatQueryCount(): void
    {
        foreach (range(1, 20) as $i) {
            $this->createPlan(100000 + $i * 1000, $this->inbound);
        }
        $this->planRule($this->plan, 9000);
        $this->startMiniAppClient();
        $admin = (new AdminUser())->setEmail('flat@shop.test')->setPassword('x')->setRoles([AdminUser::ROLE_SUPER_ADMIN])->setIsActive(true);
        $this->em->persist($admin);
        $this->em->flush();
        $this->client->loginUser($admin, 'main');

        $few = [$this->queries('/admin/plan'), $this->queries('/admin/resellers')];
        foreach (range(1, 20) as $i) {
            $user = $this->createUser('77'.$i);
            $this->em->persist((new Reseller($user))->setDiscountBp(9000));
            $this->wallets()->credit($user, 1000, 'topup', 'seed:flat:'.$i, \App\Wallet\Application\WalletActor::system());
        }
        $this->em->flush();
        $many = [$this->queries('/admin/plan'), $this->queries('/admin/resellers')];

        self::assertLessThanOrEqual($few[0] + 2, $many[0], sprintf('plan list: %d queries with 1 reseller, %d with 21', $few[0], $many[0]));
        self::assertLessThanOrEqual($few[1] + 2, $many[1], sprintf('reseller list: %d queries with 1 reseller, %d with 21', $few[1], $many[1]));
        $page = $this->client->request('GET', '/admin/plan');
        self::assertGreaterThan(0, $page->filter('[data-unit-warning]')->count(), 'the floor warnings are still shown');
    }

    /** S2: the message after a failure is read from the database: "nothing taken" only when no order exists. */
    public function testTheFailureMessageSaysWhereTheMoneyIs(): void
    {
        $handler = self::getContainer()->get(ResellerBotHandler::class);
        self::assertSame($this->botText('reseller.failed'), $handler->failureText($this->reseller, 'never-used'), 'no order: nothing was taken');

        // The debit was committed but the settlement did not finish (a crash, a timeout): the money is held.
        [, , , $confirmation] = $this->checkout()->quoteNewService($this->user, (int) $this->plan->getId());
        $order = $this->heldOrder($confirmation);
        $text = $handler->failureText($this->reseller, $confirmation->nonce);
        self::assertSame(ResellerCheckout::STATE_HELD, $this->checkout()->purchaseState($this->reseller, $confirmation->nonce)[0]);
        self::assertStringContainsString((string) $order->getTrackingCode(), $text);
        self::assertStringContainsString('120,000', $text);
        self::assertStringNotContainsString($this->botText('reseller.failed'), $text);

        // Provisioning failed: paid, held for the admins' retry.
        $this->em->getConnection()->executeStatement("UPDATE `order` SET status = 'provisioning_failed' WHERE id = ?", [$order->getId()]);
        self::assertSame($this->botText('reseller.state_provisioning_failed', ['order' => ['trackingCode' => (string) $order->getTrackingCode(), 'amount' => '120,000', 'hours' => 24]]), $handler->failureText($this->reseller, $confirmation->nonce));

        // Done, and released (the order closed and its debit came back).
        $this->em->getConnection()->executeStatement("UPDATE `order` SET status = 'provisioned' WHERE id = ?", [$order->getId()]);
        self::assertSame(ResellerCheckout::STATE_DONE, $this->checkout()->purchaseState($this->reseller, $confirmation->nonce)[0]);
        $this->em->getConnection()->executeStatement("UPDATE `order` SET status = 'cancelled' WHERE id = ?", [$order->getId()]);
        $this->em->getConnection()->executeStatement("UPDATE payment SET status = 'rejected' WHERE order_id = ?", [$order->getId()]);
        self::assertSame(ResellerCheckout::STATE_RELEASED, $this->checkout()->purchaseState($this->reseller, $confirmation->nonce)[0]);
    }

    /** S2: a second delivery while the first one's money is held says so, and takes nothing. */
    public function testASecondDeliveryWhileTheFirstIsHeldTakesNothing(): void
    {
        $this->http->reset();
        $this->pressButton(self::CUSTOMER, 'rs_plan:'.$this->plan->getId());
        $pay = array_values(array_filter($this->sentCallbacks(), static fn (string $d): bool => str_starts_with($d, 'rs_buy:')))[0] ?? self::fail();
        [, , $amount, $nonce] = explode(':', $pay);
        $this->heldOrder(new ResellerConfirmation((int) $amount, $nonce));
        $balance = $this->balance();
        $this->http->reset();

        $this->pressButton(self::CUSTOMER, $pay);

        self::assertSame(1, $this->sqlInt('SELECT COUNT(*) FROM `order`'));
        self::assertSame($balance, $this->balance());
        self::assertTrue($this->sentTextContains('120,000'), 'tells the held amount');
        self::assertFalse($this->sentTextContains($this->botText('reseller.failed')));
    }

    /** S2: the reseller button only while the wallet can pay. */
    public function testTheResellerButtonNeedsWalletPayments(): void
    {
        $button = $this->botButton('button.main.reseller');
        $this->sendText(self::CUSTOMER, '/start');
        self::assertContains($button, $this->replyButtons());

        $this->http->reset();
        $this->setting(WalletSettings::PAYMENT_ENABLED, 'false');
        $this->sendText(self::CUSTOMER, '/start');
        self::assertNotContains($button, $this->replyButtons());
        $this->sendText(self::CUSTOMER, $button);
        self::assertTrue($this->sentTextContains($this->botText('wallet.disabled')));
    }

    /**
     * @return iterable<string, array{mixed, string}>
     */
    public static function cells(): iterable
    {
        yield 'formula' => ['=1+1', "'=1+1"];
        yield 'plus' => ['+1', "'+1"];
        yield 'minus' => ['-1', "'-1"];
        yield 'at' => ['@SUM(A1)', "'@SUM(A1)"];
        yield 'tab' => ["\t=1+1", "'\t=1+1"];
        yield 'carriage return' => ["\r=1+1", "'\r=1+1"];
        yield 'plain text' => ['Ali shop', 'Ali shop'];
        yield 'a number stays a number' => [-5, '-5'];
    }

    /** S4. */
    #[DataProvider('cells')]
    public function testCsvCells(mixed $value, string $expected): void
    {
        self::assertSame($expected, ResellerAdminController::csvCell($value));
    }

    /** S5. */
    public function testReconcileReportsAResellerOrderOnSomeoneElsesAccount(): void
    {
        $this->buy();
        self::assertSame(['balances' => [], 'payments' => []], self::getContainer()->get(WalletReconciler::class)->report());

        $other = $this->createUser('8799');
        $this->em->getConnection()->executeStatement('UPDATE `order` SET user_id = ? WHERE reseller_id = ?', [$other->getId(), $this->reseller->getId()]);
        $problems = array_column(self::getContainer()->get(WalletReconciler::class)->report()['payments'], 'problem');

        self::assertContains(WalletReconciler::RESELLER_ORDER_WRONG_CUSTOMER, $problems);
    }

    /** An order of this confirmation with its wallet payment opened (debited) but not settled: a crash in between. */
    private function heldOrder(ResellerConfirmation $confirmation): Order
    {
        $order = (new Order())->setUser($this->user)->setPlan($this->plan)->setType(OrderType::NEW_SERVICE)->setAmount($confirmation->amount)->setStatus(OrderStatus::WAITING_PAYMENT)
            ->attributeToReseller($this->reseller, $confirmation->amount, 150000, 75000)->setResellerNonce($confirmation->nonce)->setTrackingCode('TRKHELD1')
            ->setMetadata(['trafficGb' => 50, 'durationDays' => 30, 'priceSnapshot' => ['finalAmount' => $confirmation->amount]]);
        $this->em->persist($order);
        $this->em->flush();
        self::getContainer()->get(WalletPayment::class)->open($order, true);

        return $order;
    }

    private function queries(string $path): int
    {
        $counter = self::getContainer()->get(QueryCounter::class);
        $this->em->clear();
        $counter->start();
        $this->client->request('GET', $path);
        $count = $counter->stop();
        self::assertSame(200, $this->client->getResponse()->getStatusCode(), $path);

        return $count;
    }

    /**
     * @return list<string>
     */
    private function replyButtons(): array
    {
        $texts = [];
        foreach ($this->http->requests('/sendMessage') as $request) {
            $markup = $this->requestBody($request)['reply_markup'] ?? null;
            $markup = is_string($markup) ? json_decode($markup, true) : $markup;
            foreach ((is_array($markup) ? $markup['keyboard'] ?? [] : []) as $row) {
                foreach ($row as $button) {
                    $texts[] = (string) ($button['text'] ?? '');
                }
            }
        }

        return $texts;
    }
}
