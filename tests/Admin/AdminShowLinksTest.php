<?php

declare(strict_types=1);

namespace App\Tests\Admin;

use App\Entity\AdminUser;
use App\Entity\BroadcastDelivery;
use App\Entity\BroadcastMessage;
use App\Entity\DiscountCode;
use App\Entity\TrialClaim;
use App\Entity\TrialPlan;
use App\Provisioning\Domain\VpnServiceStatus;
use App\Tests\Support\IntegrationTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Every detail page that holds actions or data found nowhere else is reached from the list (a Show action), and from the
 * edit page where EasyAdmin renders one; as the plans got in 99f1a27. The services list lost it with PM12 (#116), and with
 * it "Delete on panel", the page link, the retry and the claims. AdminCrudActionsReachabilityTest keeps it so.
 */
final class AdminShowLinksTest extends IntegrationTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->em->getConnection()->executeStatement('DELETE FROM admin_user');
        $this->client = new KernelBrowser(self::$kernel);
        $this->client->disableReboot();
        $this->login(AdminUser::ROLE_SUPER_ADMIN);
    }

    /**
     * @return iterable<string, array{string, bool}> the admin path and whether EasyAdmin renders its edit page
     */
    public static function cruds(): iterable
    {
        yield 'services' => ['vpn-service', true];
        yield 'panels (edited in the setup wizard)' => ['vpn-panel', false];
        yield 'payments' => ['payment', true];
        yield 'trial plans' => ['trial-plan', true];
        yield 'trial claims (no edit)' => ['trial-claim', false];
        yield 'broadcast deliveries (no edit)' => ['broadcast-delivery', false];
        yield 'discount codes' => ['discount-code', true];
    }

    #[DataProvider('cruds')]
    public function testTheListAndTheEditPageLinkToTheDetailPage(string $path, bool $hasEdit): void
    {
        $id = $this->entity($path);
        $show = sprintf('a.action-detail[href$="/admin/%s/%d"]', $path, $id);

        self::assertCount(1, $this->get('/admin/'.$path)->filter($show), 'Show on the list');
        $this->get('/admin/'.$path.'/'.$id);
        if ($hasEdit) {
            self::assertCount(1, $this->get('/admin/'.$path.'/'.$id.'/edit')->filter($show), 'Show on the edit page');
        }
    }

    public function testTheServiceDetailPageOffersDeleteOnPanelOnlyWithItsPermission(): void
    {
        $id = $this->entity('vpn-service');
        $delete = sprintf('a[href$="/admin/vpn-service/%d/delete-on-panel"]', $id);

        $detail = (string) $this->get('/admin/vpn-service')->filter(sprintf('a.action-detail[href$="/admin/vpn-service/%d"]', $id))->attr('href');
        self::assertCount(1, $this->get($detail)->filter($delete), 'a super admin');

        $this->login(AdminUser::ROLE_SUPPORT);
        $page = $this->get($detail);
        self::assertCount(0, $page->filter($delete), 'support: no SERVICES_DELETE');
        self::assertCount(1, $page->filter(sprintf('a[href$="/admin/vpn-service/%d/regenerate-config"]', $id)), 'its page is still reached');
    }

    private function entity(string $path): int
    {
        $user = $this->createUser();
        $inbound = $this->createTestInbound();
        $plan = $this->createPlan(100000, $inbound);
        $trial = (new TrialPlan())->setTitle('Free day')->setDurationDays(1)->setTrafficGb(1)->setInbound($inbound)->setBackingPlan($this->createPlan(0, $inbound)->setIsActive(false));
        $broadcast = (new BroadcastMessage())->setTitle('News')->setBody('hello')->setTargetType('all')->setStatus(BroadcastMessage::STATUS_STOPPED);
        $this->em->persist($trial);
        $this->em->persist($broadcast);

        $entity = match ($path) {
            'vpn-service' => $this->createActiveService($user, $inbound)->setStatus(VpnServiceStatus::ACTIVE),
            'vpn-panel' => $inbound->getPanel(),
            'payment' => $this->createPayment($this->createOrder($user, $plan)),
            'trial-plan' => $trial,
            'trial-claim' => (new TrialClaim())->setTelegramAccount($user->getTelegramAccount())->setTrialPlan($trial)->setStatus(TrialClaim::STATUS_PROVISIONED),
            'broadcast-delivery' => (new BroadcastDelivery())->setBroadcast($broadcast)->setChatId('9300')->setStatus(BroadcastDelivery::STATUS_FAILED)->setErrorMessage('Forbidden: bot was blocked'),
            'discount-code' => (new DiscountCode())->setCode('SHOW-1')->setType('fixed')->setValue(1000)->setAppliesTo('all'),
            default => throw new \InvalidArgumentException($path),
        };
        $this->em->persist($entity);
        $this->em->flush();

        return (int) $entity->getId();
    }

    private function get(string $url): Crawler
    {
        $crawler = $this->client->request('GET', $url);
        self::assertSame(200, $this->client->getResponse()->getStatusCode(), $url);

        return $crawler;
    }

    private function login(string $role): void
    {
        $admin = (new AdminUser())->setEmail(strtolower($role).'-'.bin2hex(random_bytes(3)).'@shop.test')->setPassword('unused')->setRoles([$role])->setIsActive(true);
        $this->em->persist($admin);
        $this->em->flush();
        $this->client->loginUser($admin, 'main');
    }
}
