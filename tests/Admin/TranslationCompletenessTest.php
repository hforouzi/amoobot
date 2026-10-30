<?php

declare(strict_types=1);

namespace App\Tests\Admin;

use App\Admin\UI\Crud\PaymentCrudController;
use App\Admin\UI\Crud\VpnServiceCrudController;
use App\Admin\UI\Support\PanelHealth;
use App\Entity\AdminUser;
use App\Entity\BotMessageLog;
use App\Entity\BroadcastMessage;
use App\Entity\DiscountCode;
use App\Entity\RequiredChannel;
use App\Entity\ServiceNotificationLog;
use App\Entity\StorePaymentMethod;
use App\Payment\Application\Settlement\SettlementOutcome;
use App\Payment\Domain\Money\AmountNormalizer;
use App\Payment\Domain\PaymentStatus;
use App\Provisioning\Domain\VpnServiceStatus;
use App\Security\AdminAuditLogger;
use App\Shop\Domain\OrderStatus;
use App\Shop\Domain\OrderType;
use App\Tests\Support\IntegrationTestCase;
use App\Tests\Support\MissingTranslationRecorder;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Everything the admin shows is translated in both locales: no page uses a label, title, column, filter, action,
 * status or flash key that fa or en does not define itself (a raw key, or English inside the Persian admin).
 * Rendered pages: the dashboard, every CRUD list, its new / first detail / first edit page, and the custom pages.
 */
final class TranslationCompletenessTest extends IntegrationTestCase
{
    /** First segments of the admin translation keys (a quoted 'segment.more' in src/Admin is a key). */
    private const KEY_PREFIXES = 'action|admin|bot_text|broadcast|common|currency|customer|dashboard|discount|entity|field|flash|format|help|locale|order|panel|panel_connection|panel_inbounds|payment_gateway|plan|role|service|settings_page|status|store_methods';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        parent::setUp();
        $connection = $this->em->getConnection();
        foreach (['admin_user', 'admin_audit_log', 'broadcast_delivery', 'broadcast_message', 'required_channel'] as $table) {
            $connection->executeStatement('DELETE FROM '.$table);
        }
        $admin = (new AdminUser())->setEmail('i18n@shop.test')->setPassword('unused')->setRoles([AdminUser::ROLE_SUPER_ADMIN])->setIsActive(true);
        $this->em->persist($admin);

        // A row for most entities, so their lists have a row and link to its detail/edit page.
        $user = $this->createUser('5550001');
        $inbound = $this->createTestInbound();
        $plan = $this->createPlan(150000, $inbound);
        $gateway = $this->createGateway();
        $this->createPayment($this->createOrder($user, $plan), $gateway);
        $service = $this->createActiveService($user, $inbound);
        $this->em->persist((new StorePaymentMethod())->setGateway($gateway)->setTitle('Card')->setSortOrder(10)->setIsActive(true)->setCurrency('IRR'));
        $this->em->persist((new DiscountCode())->setCode('I18N10')->setType(DiscountCode::TYPE_PERCENT)->setValue(10));
        $this->em->persist((new BroadcastMessage())->setTitle('Hello')->setBody('Hello all')->setTargetType('all'));
        $this->em->persist((new RequiredChannel())->setTitle('News')->setChatId('@news'));
        $this->em->persist((new ServiceNotificationLog())->setService($service)->setUser($user)->setType('expiry')->setKeyName('expiry_3d')->setSentAt(new \DateTimeImmutable())->setPayload(['days' => 3]));
        $this->em->persist((new BotMessageLog())->setTelegramId('5550001')->setDirection('in')->setUpdateType('message')->setPayload(['text' => '/start']));
        $this->em->flush();
        self::getContainer()->get(AdminAuditLogger::class)->record('i18n.test', 'Test', '1');

        $this->client = new KernelBrowser(self::$kernel);
        $this->client->disableReboot();
        $this->client->loginUser($admin, 'main');
    }

    public function testEveryAdminPageIsTranslatedInPersianAndEnglish(): void
    {
        $missing = [];
        foreach (['fa', 'en'] as $locale) {
            $this->client->request('GET', '/admin/locale/'.$locale, ['redirect_to' => '/admin']);
            $this->recorder()->reset();
            foreach ($this->pages() as $url) {
                $this->client->request('GET', $url);
                $status = $this->client->getResponse()->getStatusCode();
                self::assertContains($status, [200, 302], $locale.' GET '.$url);
            }
            foreach ($this->recorder()->missing() as $key) {
                $missing[] = $key;
            }
        }

        self::assertSame([], $missing, 'translation keys a locale does not define ("locale|domain|key")');
    }

    /**
     * Keys written literally in the admin templates ('key'|trans) exist in both locales, in the domain the template
     * really uses (its trans_default_domain, else "messages"), whether or not a test renders that branch.
     */
    public function testEveryLiteralKeyInTheAdminTemplatesIsDefined(): void
    {
        $translator = self::getContainer()->get(TranslatorInterface::class);
        $finder = (new Finder())->files()->name('*.twig')->in([dirname(__DIR__, 2).'/templates/admin', dirname(__DIR__, 2).'/templates/bundles']);
        $missing = [];
        foreach ($finder as $file) {
            // Twig's default domain is "messages" unless the template declares its own.
            $default = preg_match("/trans_default_domain\\s+'([a-zA-Z]+)'/", $file->getContents(), $declared) ? $declared[1] : 'messages';
            preg_match_all("/'([a-z0-9_]+(?:\\.[a-z0-9_]+)+)'\\s*\\|\\s*trans(?:\\(\\s*\\{[^}]*\\}\\s*,\\s*'([a-zA-Z]+)'\\s*\\))?/", $file->getContents(), $matches, PREG_SET_ORDER);
            foreach ($matches as $match) {
                $domain = '' !== ($match[2] ?? '') ? $match[2] : $default;
                foreach (['fa', 'en'] as $locale) {
                    if (!$translator->getCatalogue($locale)->defines($match[1], $domain)) {
                        $missing[] = sprintf('%s: %s|%s|%s', $file->getRelativePathname(), $locale, $domain, $match[1]);
                    }
                }
            }
        }

        self::assertSame([], array_values(array_unique($missing)));
    }

    /**
     * Keys the admin code passes as literals (labels, help, actions, choices, flash messages) and the keys it builds
     * from a value ('status.'.$status, ...) for every value it can get, exist in both locales.
     */
    public function testEveryKeyTheAdminCodeUsesIsDefined(): void
    {
        $keys = [];
        $finder = (new Finder())->files()->name('*.php')->in(dirname(__DIR__, 2).'/src/Admin');
        foreach ($finder as $file) {
            // Not keys: audit action names (->record('plan.bulk_price_adjust', ...)) and DQL paths ('entity.plan').
            $code = (string) preg_replace("/->record\\(\\s*'[^']*'/", '', $file->getContents());
            preg_match_all("/'((?:".self::KEY_PREFIXES.')\\.[a-zA-Z0-9_]+(?:\\.[a-zA-Z0-9_]+)*)\'/', $code, $matches);
            foreach (preg_grep('/^entity\\.[a-zA-Z_]+$/', $matches[1], PREG_GREP_INVERT) ?: [] as $key) {
                $keys[$key] = $file->getRelativePathname();
            }
        }
        $dynamic = [
            'status.' => [...PaymentStatus::ALL, ...OrderStatus::ALL, ...VpnServiceStatus::ALL],
            'order.type.' => OrderType::ALL,
            'panel.health.' => [PanelHealth::CONNECTED, PanelHealth::DEGRADED, PanelHealth::ERROR, PanelHealth::UNTESTED],
            'flash.payment.' => array_map(static fn (SettlementOutcome $o): string => $o->value, SettlementOutcome::cases()),
            'payment.tabs.' => array_keys(PaymentCrudController::TABS),
            'service.tabs.' => VpnServiceCrudController::TABS,
            'currency.' => [AmountNormalizer::SHOP_CURRENCY],
        ];
        foreach ($dynamic as $prefix => $values) {
            foreach ($values as $value) {
                $keys[$prefix.$value] = 'built from '.$prefix;
            }
        }

        $translator = self::getContainer()->get(TranslatorInterface::class);
        $missing = [];
        foreach ($keys as $key => $where) {
            foreach (['fa', 'en'] as $locale) {
                if (!$translator->getCatalogue($locale)->defines($key, 'admin')) {
                    $missing[] = sprintf('%s: %s|%s', $where, $locale, $key);
                }
            }
        }
        sort($missing);

        self::assertSame([], $missing);
    }

    /**
     * The Persian admin shows no English column header (beyond the few technical abbreviations kept as they are).
     */
    public function testPersianListsHaveNoEnglishColumnHeaders(): void
    {
        $this->client->request('GET', '/admin/locale/fa', ['redirect_to' => '/admin']);
        $allowed = ['ID', 'VPN', 'IP', 'URL', 'SNI', 'UUID', 'GB'];
        $english = [];
        foreach ($this->listPages() as $url) {
            $crawler = $this->client->request('GET', $url);
            if ($this->client->getResponse()->isRedirect()) {
                $crawler = $this->client->followRedirect();
            }
            foreach ($crawler->filter('table thead th')->each(static fn (Crawler $th): string => trim($th->text())) as $header) {
                $words = array_diff(preg_split('/[\s()\/]+/u', $header, -1, PREG_SPLIT_NO_EMPTY) ?: [], $allowed);
                if ([] !== $words && [] !== preg_grep('/^[A-Za-z][A-Za-z_.\-]*$/', $words)) {
                    $english[] = $url.': '.$header;
                }
            }
        }

        self::assertSame([], $english);
    }

    /**
     * @return list<string>
     */
    private function pages(): array
    {
        $pages = ['/admin', '/admin/settings?tab=sales', '/admin/settings?tab=automation', '/admin/settings?tab=channels', '/admin/settings?tab=branding', '/admin/settings?tab=advanced', '/admin/panel-setup/new', '/admin/payment-gateways/install', '/admin/plans/bulk-adjust-prices'];
        foreach (['pending', 'needs_review', 'provisioning_failed', 'confirmed', 'rejected'] as $tab) {
            $pages[] = '/admin/payment?tab='.$tab;
        }

        foreach ($this->listPages() as $index) {
            $crawler = $this->client->request('GET', $index);
            if ($this->client->getResponse()->isRedirect()) {
                $crawler = $this->client->followRedirect();
            }
            $pages[] = $index;
            $linked = [];
            foreach ($crawler->filter('a[href]')->each(static fn (Crawler $a): string => (string) $a->attr('href')) as $href) {
                $path = (string) parse_url($href, PHP_URL_PATH);
                if (preg_match('#^'.preg_quote($index, '#').'/(new|\d+|\d+/edit)$#', $path, $m)) {
                    $linked[match (true) { 'new' === $m[1] => 'new', str_ends_with($m[1], '/edit') => 'edit', default => 'detail' }] ??= $path;
                }
            }
            array_push($pages, ...array_values($linked));
        }
        $panelId = $this->em->getConnection()->fetchOne('SELECT MIN(id) FROM vpn_panel');
        $pages[] = '/admin/panel-setup/'.$panelId;

        return $pages;
    }

    /**
     * @return list<string> the index path of every CRUD controller
     */
    private function listPages(): array
    {
        $paths = [];
        foreach (self::getContainer()->get(RouterInterface::class)->getRouteCollection() as $route) {
            if ('index' === $route->getDefault('crudAction')) {
                $paths[] = $route->getPath();
            }
        }
        sort($paths);

        return $paths;
    }

    private function recorder(): MissingTranslationRecorder
    {
        return self::getContainer()->get(MissingTranslationRecorder::class);
    }
}
