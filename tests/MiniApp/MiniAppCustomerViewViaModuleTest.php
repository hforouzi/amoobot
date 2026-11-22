<?php

declare(strict_types=1);

namespace App\Tests\MiniApp;

use App\Entity\VpnService;
use App\Module\Domain\CustomerActionInput;
use App\Module\Domain\CustomerActions;
use App\Provisioning\Application\VpnCustomerView;
use App\Provisioning\Domain\Dto\VpnUsage;
use App\Tests\Support\BotAdminTestTrait;
use App\Tests\Support\CustomerViewFixtures;
use App\Tests\Support\IntegrationTestCase;
use App\Tests\Support\MiniAppTestTrait;
use App\Tests\Support\SpyProductModule;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * PM1d: the Mini App's customer views (MiniAppCustomerController: home and services cards, the service view, the
 * in-app subscription view) ask the service's product module what to show (customerView()) and, on the service view,
 * what to offer (customerActions(), channel 'miniapp'); the self-service actions (MiniAppServiceActionController) are
 * run by the module (runCustomerAction()). Once per service and request, with the service the ownership check found;
 * someone else's service is a 404 that never reaches the module. What the customer sees is pinned by the snapshots
 * (MiniAppCustomerViewSnapshotTest); this pins the path.
 */
final class MiniAppCustomerViewViaModuleTest extends IntegrationTestCase
{
    use BotAdminTestTrait;
    use CustomerViewFixtures;
    use MiniAppTestTrait;

    private SpyProductModule $spy;
    /** @var array{token: string, csrf: string} */
    private array $session;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCustomerView();
        $this->spy = SpyProductModule::install(self::getContainer());
        $this->startMiniAppClient();
        $this->session = $this->authenticate(self::VIEW_CUSTOMER, 'Sara');
        $this->spy->calls = [];
    }

    protected function tearDown(): void
    {
        $this->tearDownCustomerView();
        parent::tearDown();
    }

    public function testTheCardsAskTheModuleOnceForEachService(): void
    {
        $first = (int) $this->stateService('active')->getId();
        $second = (int) $this->stateService('expired')->getId();

        foreach (['/app/view/services', '/app/view/home'] as $path) {
            $this->spy->calls = [];
            $this->appGet($path, $this->session);

            self::assertSame(200, $this->httpStatus(), $path);
            self::assertSame(['customerView', 'customerView'], $this->spy->methods(), $path);
            $ids = array_map(static fn (array $call): ?int => $call['args'][0]->getId(), $this->spy->calls);
            sort($ids);
            self::assertSame([$first, $second], $ids, $path);
        }
    }

    public function testTheServiceViewAsksForItsViewAndItsActionsOnce(): void
    {
        $id = (int) $this->stateService('active')->getId();

        $this->appGet('/app/view/services/'.$id, $this->session);

        self::assertSame(200, $this->httpStatus());
        self::assertSame(['customerView', 'customerActions'], $this->spy->methods());
        $this->assertService($id, $this->spy->calls[0]['args']);
        self::assertInstanceOf(VpnCustomerView::class, $this->spy->calls[0]['result']);
        self::assertCount(2, $this->spy->calls[1]['args']);
        $this->assertService($id, [$this->spy->calls[1]['args'][0]]);
        self::assertSame(CustomerActions::CHANNEL_MINIAPP, $this->spy->calls[1]['args'][1]);
        $actions = $this->spy->calls[1]['result'];
        self::assertInstanceOf(CustomerActions::class, $actions);
        self::assertTrue($actions->has(CustomerActions::RENEW));
        self::assertNotNull($actions->pageUrl);
        self::assertStringContainsString((string) $actions->pageUrl, html_entity_decode((string) $this->client->getResponse()->getContent()));
    }

    public function testTheInAppSubscriptionViewAsksForTheViewOnce(): void
    {
        $id = (int) $this->stateService('active')->getId();

        $this->appGet('/app/view/services/'.$id.'/subscription?platform=android', $this->session);

        self::assertSame(200, $this->httpStatus());
        self::assertSame(['customerView'], $this->spy->methods());
        $this->assertService($id, $this->spy->calls[0]['args']);
    }

    public function testSomeoneElsesServiceIsA404ThatNeverReachesTheModule(): void
    {
        $other = (int) $this->stateService('active', $this->createUser('7302'))->getId();

        foreach (['/app/view/services/%d', '/app/view/services/%d/subscription'] as $path) {
            $this->appGet(sprintf($path, $other), $this->session);
            self::assertSame(404, $this->httpStatus(), $path);
        }
        foreach (['send-link', 'send-qr', 'resend-config', 'refresh'] as $action) {
            $this->appPost('/app/api/services/'.$other.'/'.$action, $this->session);
            self::assertSame(404, $this->httpStatus(), $action);
        }

        self::assertSame([], $this->spy->methods());
    }

    /**
     * @return iterable<string, array{string, string, list<string>}>
     */
    public static function actions(): iterable
    {
        // The spy records a call when it returns: the view the bot's delivery read, then the action that ran it.
        yield 'send-link' => ['send-link', CustomerActions::SEND_LINK, ['customerView', 'runCustomerAction']];
        yield 'send-qr' => ['send-qr', CustomerActions::SEND_QR, ['customerView', 'runCustomerAction']];
        yield 'resend-config' => ['resend-config', CustomerActions::RESEND_CONFIG, ['customerView', 'runCustomerAction']];
        yield 'refresh' => ['refresh', CustomerActions::REFRESH, ['runCustomerAction']];
    }

    /**
     * @param list<string> $methods
     */
    #[DataProvider('actions')]
    public function testAnActionIsRunByTheModuleOnce(string $path, string $action, array $methods): void
    {
        $id = (int) $this->stateService('active')->getId();
        $this->driver()->usage = new VpnUsage(trafficUsedGb: 20, trafficLimitGb: 50, usedBytes: 20 * 1073741824, totalBytes: 50 * 1073741824);

        $this->appPost('/app/api/services/'.$id.'/'.$path, $this->session);

        self::assertSame(200, $this->httpStatus());
        self::assertSame($methods, $this->spy->methods());
        $call = $this->spy->calls[array_key_last($this->spy->calls)];
        self::assertCount(3, $call['args']);
        $this->assertService($id, [$call['args'][0]]);
        self::assertSame($action, $call['args'][1]);
        $input = $call['args'][2];
        self::assertInstanceOf(CustomerActionInput::class, $input);
        self::assertSame(self::VIEW_CUSTOMER, (string) $input->account->getTelegramId());
        self::assertSame(self::VIEW_CUSTOMER, $input->chatId);
        self::assertSame('', $input->callbackId);
        self::assertFalse($call['result']->failed);
    }

    public function testAFailedRefreshIsTheModulesFailedResult(): void
    {
        $id = (int) $this->stateService('active')->getId();
        $this->driver()->usageFails = true;

        $this->appPost('/app/api/services/'.$id.'/refresh', $this->session);

        self::assertSame(502, $this->httpStatus());
        self::assertSame(['runCustomerAction'], $this->spy->methods());
        self::assertTrue($this->spy->calls[0]['result']->failed);
    }

    /**
     * @param list<mixed> $args
     */
    private function assertService(int $id, array $args): void
    {
        self::assertCount(1, $args);
        self::assertInstanceOf(VpnService::class, $args[0]);
        self::assertSame($id, $args[0]->getId());
    }
}
