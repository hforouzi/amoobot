<?php

declare(strict_types=1);

namespace App\Tests\Shop\InboundMove;

use App\Entity\AdminUser;
use App\Entity\Plan;
use App\Entity\Setting;
use App\Payment\Application\Settlement\PaymentSettlementService;
use App\Payment\Application\Settlement\VerifiedPayment;
use App\Payment\Domain\Dto\PaymentVerificationResult;
use App\Payment\Domain\PaymentStatus;
use App\Provisioning\Domain\VpnServiceStatus;
use App\Shop\Application\Checkout\PlanCatalog;
use App\Shop\Application\InboundMoveSettings;
use App\Tests\Shop\PlanInboundChange\PlanInboundChangeFixtures;
use App\Tests\Support\IntegrationTestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Field\ChoiceFormField;

/**
 * PR P3 added tables, a column and settings; PR P4 lets only the move runner and its command read them
 * (docs/design/plan-inbound-change.md 5.7, 9). Sales, settlement, regenerate and saving a plan behave exactly the same
 * with plans.inbound_move_enabled on (and every limit changed) as with the defaults, on the fake modern 3x-ui panel.
 * Neither run writes a run, a move or vpn_service.inbound_changed_at: only app:plan:apply-inbounds --apply does.
 */
final class InboundMoveSwitchHasNoEffectTest extends IntegrationTestCase
{
    use PlanInboundChangeFixtures;

    private KernelBrowser $client;

    public function testTheSwitchAndItsLimitsChangeNothing(): void
    {
        $off = $this->observe(false);
        $on = $this->observe(true);

        self::assertSame($off, $on);
        // Each scenario did run.
        self::assertSame(['Gold'], $off['sales']);
        self::assertSame('CONFIRMED', $off['settlement']['outcome']);
        self::assertSame(['status' => 'active', 'inbound' => 'DE-N'], array_intersect_key($off['settlement']['service'], ['status' => 1, 'inbound' => 1]));
        self::assertContains('POST panel/api/clients/add', $off['settlement']['panel']);
        self::assertStringContainsString('refreshed from the panel', implode(' ', $off['regenerate']['flash']));
        self::assertSame('DE-N', $off['regenerate']['service']['inbound'], 'not moved (digits folded)');
        self::assertNotSame([], $off['regenerate']['panel']);
        self::assertSame('DE-1', $off['plan_save']['inbound']);
        self::assertTrue($off['plan_save']['services_unchanged']);
        self::assertSame([], $off['plan_save']['panel'], 'saving a plan calls no panel');
    }

    public function testNothingOutsideTheSettingsPageReadsTheNewSettingsOrEntities(): void
    {
        $readers = [];
        $src = dirname(__DIR__, 3).'/src';
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($src, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if (!$file instanceof \SplFileInfo || 'php' !== $file->getExtension()) {
                continue;
            }
            $code = (string) file_get_contents($file->getPathname());
            if (1 === preg_match('/\b(InboundMoveSettings|InboundMoveRun|ServiceInboundMove)\b|InboundChangedAt|inbound_move_|inbound_changed_at/', $code)) {
                $readers[] = str_replace('\\', '/', substr($file->getPathname(), strlen($src) + 1));
            }
        }
        sort($readers);

        self::assertSame([
            // P9: the actions of the web pages (P7) and the Mini App admin, one implementation calling the command's
            // planner and controls.
            'Admin/Application/InboundMoveActions.php',
            'Admin/Application/SettingsAdministration.php',
            // P7: the web pages of the move (the plan's run block, the service's move line, the list filter, the
            // Apply page and its buttons). They read, or call the same planner and controls as the command.
            'Admin/UI/Crud/PlanCrudController.php',
            'Admin/UI/Crud/VpnServiceCrudController.php',
            'Admin/UI/InboundMoveAdminController.php',
            'Command/AutomationRunCommand.php',
            'Command/PlanApplyInboundsCommand.php',
            'Entity/InboundMoveRun.php',
            'Entity/ServiceInboundMove.php',
            'Entity/VpnService.php',
            // P9: the Mini App admin's screens of the move (route names, the plan's run block, the service's move
            // block, the list filter, the Apply screen and its buttons), through InboundMoveActions.
            'MiniApp/Admin/AdminRoutePermissions.php',
            'MiniApp/UI/Admin/MiniAppAdminInboundMoveController.php',
            'MiniApp/UI/Admin/MiniAppAdminPlanController.php',
            'MiniApp/UI/Admin/MiniAppAdminServiceController.php',
            // P5: the display guard reads inbound_changed_at (linksPending()), no setting and no move row.
            'Provisioning/Application/FinalConfigLinkProvider.php',
            'Provisioning/Application/InboundAttachAlerts.php',
            // P6: the run controls of the command (pause, cancel, retire now).
            'Provisioning/Application/InboundMoveControls.php',
            // P7: what the pages show (read only).
            'Provisioning/Application/InboundMoveOverview.php',
            'Provisioning/Application/InboundMovePass.php',
            'Provisioning/Application/InboundMovePlanner.php',
            'Provisioning/Application/InboundMoveRunner.php',
            'Provisioning/Application/InboundMoveScheduler.php',
            'Provisioning/Application/InboundMoveScope.php',
            'Shop/Application/InboundMoveSettings.php',
        ], $readers, 'P4-P9: only the runner (its alerts and controls, the command, the scheduled task), the display guard, the web pages of P7 and the Mini App screens of P9 read them; no sale, settlement, regenerate or plan save does');
    }

    /**
     * One pass over sales, settlement, regenerate and a plan save, on fresh data, reduced to what does not depend on
     * ids, times or random credentials.
     *
     * @return array{sales: list<string>, settlement: array<string, mixed>, regenerate: array<string, mixed>, plan_save: array<string, mixed>, untouched: array<string, int>}
     */
    private function observe(bool $switchOn): array
    {
        $this->purge();
        $this->em->clear();
        $this->http->reset();
        $this->setUpInboundChange();
        if ($switchOn) {
            foreach ([InboundMoveSettings::ENABLED => ['true', 'boolean'], InboundMoveSettings::DETACH_GRACE_HOURS => ['0', 'number'], InboundMoveSettings::BATCH_SIZE => ['1', 'number'], InboundMoveSettings::PAUSE_SECONDS => ['60', 'number']] as $key => [$value, $type]) {
                $this->em->persist((new Setting())->setKeyName($key)->setValue($value)->setType($type));
            }
            $this->em->flush();
            self::assertTrue(self::getContainer()->get(InboundMoveSettings::class)->enabled());
        }
        $this->client = $this->admin();
        $this->client->request('GET', '/admin/locale/en');

        // Sales: the catalog.
        $sales = array_map(static fn (Plan $p): string => (string) $p->getTitle(), self::getContainer()->get(PlanCatalog::class)->forSale());

        // Settlement of a new order of the plan: the client is created on DE-1.
        $this->fake->requests = [];
        $order = $this->createOrder($this->managed($this->owner), $this->managed($this->gold));
        $payment = $this->createPayment($order, null, PaymentStatus::PENDING);
        $result = self::getContainer()->get(PaymentSettlementService::class)->settle($payment, VerifiedPayment::fromGateway(new PaymentVerificationResult(success: true, paid: true, transactionId: 'tx-p3', amount: '1000000', currency: 'IRR')), 'test');
        $sold = $this->em->getConnection()->fetchAssociative('SELECT s.status, i.title AS inbound, s.traffic_limit_gb, s.inbound_changed_at FROM vpn_service s LEFT JOIN vpn_inbound i ON i.id = s.inbound_id WHERE s.order_id = ?', [$order->getId()]) ?: [];
        $settlement = ['outcome' => $result->outcome->name, 'service' => $sold, 'panel' => $this->panelCalls()];

        // Regenerate of a service left on DE-1 after the plan moved to NL-2.
        $service = $this->planService($this->gold, $this->de, VpnServiceStatus::ACTIVE, 1);
        $this->sellOn($this->gold, $this->nl);
        $this->fake->requests = [];
        $this->client->request('GET', '/admin/vpn-service/'.$service->getId().'/regenerate-config');
        $this->client->followRedirect();
        $regenerated = $this->em->getConnection()->fetchAssociative('SELECT i.title AS inbound, s.config_links, s.config_text, s.remote_id, s.inbound_changed_at FROM vpn_service s LEFT JOIN vpn_inbound i ON i.id = s.inbound_id WHERE s.id = ?', [$service->getId()]) ?: [];
        $regenerate = ['flash' => $this->flashes(), 'service' => self::anonymous($regenerated), 'panel' => $this->panelCalls()];

        // Saving the plan back on DE-1 with a new price.
        $this->sellOn($this->gold, $this->nl);
        $services = self::anonymous($this->em->getConnection()->fetchAllAssociative('SELECT status, inbound_id, config_links, remote_id, inbound_changed_at FROM vpn_service ORDER BY id'));
        $this->fake->requests = [];
        $this->saveForm($this->gold, ['inbound' => (string) $this->de->getId(), 'price' => '120000']);
        $plan = array_diff_key($this->row('plan', (int) $this->gold->getId()), ['id' => 1, 'created_at' => 1, 'updated_at' => 1, 'inbound_id' => 1]);
        $planSave = [
            'flash' => $this->flashes(),
            'plan' => self::anonymous($plan),
            'inbound' => $this->em->getConnection()->fetchOne('SELECT i.title FROM plan p JOIN vpn_inbound i ON i.id = p.inbound_id WHERE p.id = ?', [$this->gold->getId()]),
            'services_unchanged' => $services === self::anonymous($this->em->getConnection()->fetchAllAssociative('SELECT status, inbound_id, config_links, remote_id, inbound_changed_at FROM vpn_service ORDER BY id')),
            'panel' => $this->panelCalls(),
        ];

        $untouched = [
            'inbound_move_run' => (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM inbound_move_run'),
            'service_inbound_move' => (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM service_inbound_move'),
            'inbound_changed_at' => (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM vpn_service WHERE inbound_changed_at IS NOT NULL'),
        ];
        self::assertSame(['inbound_move_run' => 0, 'service_inbound_move' => 0, 'inbound_changed_at' => 0], $untouched, $switchOn ? 'switch on' : 'switch off');

        return ['sales' => $sales, 'settlement' => self::anonymous($settlement), 'regenerate' => $regenerate, 'plan_save' => $planSave, 'untouched' => $untouched];
    }

    /** @return list<string> the fake panel's requests since the last reset, ids and emails folded */
    private function panelCalls(): array
    {
        return array_map(static fn (string $line): string => (string) preg_replace(['#/subLinks/[^/\s]+#', '/\d+/'], ['/subLinks/SUB', 'N'], $line), $this->fake->requestLines());
    }

    /** @return list<string> the flash messages on the current page */
    private function flashes(): array
    {
        $texts = $this->client->getCrawler()->filter('.alert')->each(static fn ($node): string => trim((string) preg_replace('/\s+/', ' ', $node->text())));

        return array_map(static fn (string $t): string => (string) preg_replace('/\d+/', 'N', $t), $texts);
    }

    /**
     * Numbers folded (ids differ between the two passes), credentials of the fake (random per sale) included.
     *
     * @template T
     *
     * @param T $value
     *
     * @return T
     */
    private static function anonymous(mixed $value): mixed
    {
        if (is_array($value)) {
            return array_map(self::anonymous(...), $value);
        }

        return is_string($value) ? preg_replace('/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}|\d+/', 'N', $value) : $value;
    }

    /**
     * @param array<string, string> $fields
     */
    private function saveForm(Plan $plan, array $fields): void
    {
        $form = $this->client->request('GET', '/admin/plan/'.$plan->getId().'/edit')->filter('form[name="Plan"]')->form();
        foreach ($fields as $name => $value) {
            $field = $form['Plan['.$name.']'];
            $field instanceof ChoiceFormField ? $field->select($value) : $field->setValue($value);
        }
        $this->client->submit($form);
        self::assertTrue($this->client->getResponse()->isRedirect(), mb_substr(strip_tags((string) $this->client->getResponse()->getContent()), 0, 1500));
        $this->client->followRedirect();
        $this->em->clear();
    }

    private function admin(): KernelBrowser
    {
        $admin = (new AdminUser())->setEmail('p3-'.bin2hex(random_bytes(4)).'@example.test')->setPassword('unused')->setRoles([AdminUser::ROLE_SUPER_ADMIN]);
        $this->em->persist($admin);
        $this->em->flush();
        $client = new KernelBrowser(self::$kernel);
        $client->disableReboot();
        $client->loginUser($admin, 'main');

        return $client;
    }
}
