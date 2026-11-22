<?php

declare(strict_types=1);

namespace App\Tests\Admin\Shell;

use App\Entity\AdminUser;
use App\Tests\Support\IntegrationTestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * AD1 (docs/design/admin-redesign.md, 7.1): with the switch off, the admin shell (head, sidebar menu, top bar) renders
 * byte for byte as before the modern layout existed. The snapshots in fixtures/classic_shell were taken from develop/v3
 * at 1e90e9c, before any AD1 change; regenerate them only on purpose (UPDATE_CLASSIC_SNAPSHOTS=1).
 */
final class ClassicShellSnapshotTest extends IntegrationTestCase
{
    private const PAGES = [
        'dashboard' => '/admin',
        'payments' => '/admin/payment',
        'settings' => '/admin/settings',
        'wallets' => '/admin/wallets',
        // Pages on plain #[Route]s: no EasyAdmin context, so EasyAdmin's layout falls back to custom_page.html.twig.
        'gateway_install' => '/admin/payment-gateways/install',
        'panel_setup' => '/admin/panel-setup/new',
    ];

    private KernelBrowser $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->em->getConnection()->executeStatement('DELETE FROM admin_user');
        $this->em->getConnection()->executeStatement("DELETE FROM setting WHERE key_name IN ('admin.ui.layout', 'brand.name', 'brand.accent_color')");
        $this->client = new KernelBrowser(self::$kernel);
        $this->client->disableReboot();
    }

    public function testClassicShellIsUnchanged(): void
    {
        foreach ([AdminUser::ROLE_SUPER_ADMIN => 'super', AdminUser::ROLE_ADMIN => 'admin', AdminUser::ROLE_SUPPORT => 'support'] as $role => $name) {
            $admin = (new AdminUser())->setEmail('snapshot-'.$name.'@shop.test')->setPassword('unused')->setRoles([$role])->setIsActive(true);
            $this->em->persist($admin);
            $this->em->flush();
            $this->client->loginUser($admin, 'main');
            foreach (self::PAGES as $page => $path) {
                $this->client->request('GET', $path);
                $status = $this->client->getResponse()->getStatusCode();
                $html = 200 === $status ? self::shell((string) $this->client->getResponse()->getContent()) : 'HTTP '.$status;
                $file = __DIR__.'/fixtures/classic_shell/'.$name.'-'.$page.'.html';
                if ('1' === getenv('UPDATE_CLASSIC_SNAPSHOTS')) {
                    @mkdir(dirname($file), 0777, true);
                    file_put_contents($file, $html);
                }
                self::assertFileExists($file);
                self::assertSame(file_get_contents($file), $html, sprintf('%s %s: the classic shell changed', $name, $path));
            }
        }
    }

    /**
     * The shell of an admin page: everything up to the page's own content (head, sidebar with the menu, top bar), with
     * the per-request values (CSRF tokens, nonces) blanked.
     */
    private static function shell(string $html): string
    {
        $end = strpos($html, '<article class="content');
        $shell = false === $end ? $html : substr($html, 0, $end);

        return (string) preg_replace('/(name="(?:[a-z_]+\[)?_token\]?")([^>]*?)value="[^"]*"/', '$1$2value=""', $shell);
    }
}
