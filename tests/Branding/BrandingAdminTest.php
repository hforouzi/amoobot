<?php

declare(strict_types=1);

namespace App\Tests\Branding;

use App\Branding\BrandingSettings;
use App\Entity\AdminUser;
use App\Tests\Support\BrandImages;
use App\Tests\Support\IntegrationTestCase;
use App\Tests\Support\QueryCounter;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * The "branding" settings tab (SETTINGS_MANAGE, CSRF, audited) and the public logo route (/brand/logo/{name}).
 */
final class BrandingAdminTest extends IntegrationTestCase
{
    private KernelBrowser $client;
    private string $work;

    protected function setUp(): void
    {
        parent::setUp();
        $connection = $this->em->getConnection();
        $connection->executeStatement('DELETE FROM admin_audit_log');
        $connection->executeStatement('DELETE FROM admin_user');
        $this->client = new KernelBrowser(self::$kernel);
        $this->client->disableReboot();
        $this->work = sys_get_temp_dir().'/amoobot-brand-test-'.bin2hex(random_bytes(4));
        mkdir($this->work);
    }

    protected function tearDown(): void
    {
        foreach (array_merge(glob($this->work.'/*') ?: [], glob($this->directory().'/*') ?: []) as $file) {
            unlink($file);
        }
        rmdir($this->work);
        parent::tearDown();
    }

    public function testTheTabSavesNameColourAndLogoAndAuditsIt(): void
    {
        $this->login(AdminUser::ROLE_SUPER_ADMIN);
        $this->client->request('GET', '/admin/settings?tab=branding');
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertStringContainsString('name="brand_logo"', (string) $this->client->getResponse()->getContent());

        $this->save(['brand_name' => 'فروشگاه‌ امو <VPN>', 'brand_accent_color' => '#1D4ED8'], BrandImages::png());

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $settings = $this->settings();
        self::assertSame('فروشگاه‌ امو <VPN>', $settings[BrandingSettings::NAME], 'stored exactly as typed');
        self::assertSame('#1D4ED8', $settings[BrandingSettings::ACCENT]);
        self::assertMatchesRegularExpression('/^[a-f0-9]{32}\.png$/', $settings[BrandingSettings::LOGO]);
        self::assertFileExists($this->directory().'/'.$settings[BrandingSettings::LOGO]);

        $audit = $this->em->getConnection()->fetchAllAssociative('SELECT action, source, details FROM admin_audit_log');
        self::assertCount(1, $audit);
        self::assertSame(['settings.update', 'web'], [$audit[0]['action'], $audit[0]['source']]);
        $details = json_decode((string) $audit[0]['details'], true);
        self::assertSame(['page' => 'branding', 'brand_name' => 'فروشگاه‌ امو <VPN>', 'brand_accent_color' => '#1d4ed8', 'logo' => 'replaced'], $details);

        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('/brand/logo/'.$settings[BrandingSettings::LOGO], $html, 'the current logo is shown');
        self::assertStringContainsString('&lt;VPN&gt;', $html);
        self::assertStringNotContainsString('<VPN>', $html);
    }

    /**
     * @return iterable<string, array{array<string, string>, string}>
     */
    public static function refusedForms(): iterable
    {
        yield 'name with a leading space' => [['brand_name' => ' Amoo'], 'flash.settings.brand_name.spaces'];
        yield 'name with a trailing space' => [['brand_name' => 'Amoo '], 'flash.settings.brand_name.spaces'];
        yield 'name with a line break' => [['brand_name' => "Amoo\nVPN"], 'flash.settings.brand_name.control'];
        yield 'name with a right-to-left override' => [['brand_name' => "Amoo\u{202E}NPV"], 'flash.settings.brand_name.control'];
        yield 'name with a zero-width space' => [['brand_name' => "Amoo\u{200B}VPN"], 'flash.settings.brand_name.control'];
        yield 'name with a BOM' => [['brand_name' => "\u{FEFF}Amoo"], 'flash.settings.brand_name.control'];
        yield 'name with a private-use character' => [['brand_name' => "Amoo\u{E000}"], 'flash.settings.brand_name.characters'];
        yield 'name with invalid UTF-8' => [['brand_name' => "Amoo\xC3"], 'flash.settings.brand_name.characters'];
        yield 'name too long' => [['brand_name' => str_repeat('ا', BrandingSettings::NAME_MAX + 1)], 'flash.settings.brand_name.too_long'];
        yield 'colour without #' => [['brand_accent_color' => '166534'], 'flash.settings.brand_accent'];
        yield 'colour too short' => [['brand_accent_color' => '#16653'], 'flash.settings.brand_accent'];
        yield 'short colour form' => [['brand_accent_color' => '#163'], 'flash.settings.brand_accent'];
        yield 'colour too long' => [['brand_accent_color' => '#1665340'], 'flash.settings.brand_accent'];
        yield 'colour not hex' => [['brand_accent_color' => '#16653g'], 'flash.settings.brand_accent'];
        yield 'colour with a trailing line break' => [['brand_accent_color' => "#166534\n"], 'flash.settings.brand_accent'];
        yield 'colour as css' => [['brand_accent_color' => 'red;background:url(x)'], 'flash.settings.brand_accent'];
    }

    /**
     * @param array<string, string> $form
     */
    #[DataProvider('refusedForms')]
    public function testARefusedFormSavesNothing(array $form, string $error): void
    {
        $this->login(AdminUser::ROLE_SUPER_ADMIN);
        $this->save($form + ['brand_name' => 'Amoo', 'brand_accent_color' => '#166534'], BrandImages::png());

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertFlash($error);
        $this->assertNothingSaved();
    }

    public function testARefusedLogoSavesNothingElse(): void
    {
        $this->login(AdminUser::ROLE_SUPER_ADMIN);
        $this->save(['brand_name' => 'Amoo', 'brand_accent_color' => '#166534'], '<svg xmlns="http://www.w3.org/2000/svg"/>', 'logo.svg');

        $this->assertFlash('branding.logo.extension', ['%allowed%' => 'png, jpg, jpeg, webp']);
        $this->assertNothingSaved();
    }

    public function testEmptyValuesMeanNoNameAndTheDefaultColour(): void
    {
        $this->login(AdminUser::ROLE_SUPER_ADMIN);
        $this->save(['brand_name' => '', 'brand_accent_color' => '']);

        $this->assertFlash('flash.settings.branding_saved');
        $branding = $this->branding();
        self::assertNull($branding->name);
        self::assertNull($branding->logo);
        self::assertSame('#166534', $branding->accentColor);
    }

    public function testAMissingOrWrongCsrfTokenSavesNothing(): void
    {
        $this->login(AdminUser::ROLE_SUPER_ADMIN);
        $this->client->request('POST', '/admin/settings?tab=branding', ['brand_name' => 'Amoo', 'brand_accent_color' => '#166534', '_token' => 'forged']);
        $this->assertFlash('flash.csrf_expired');
        $this->assertNothingSaved();

        $this->save(['brand_name' => 'Amoo'], BrandImages::png());
        $logo = (string) $this->settings()[BrandingSettings::LOGO];
        $this->client->request('POST', '/admin/settings/branding/remove-logo', ['_token' => 'forged']);
        self::assertSame($logo, $this->settings()[BrandingSettings::LOGO]);
        self::assertFileExists($this->directory().'/'.$logo);
    }

    public function testReplacingTheLogoDeletesTheOldFileAndItsUrl(): void
    {
        $this->login(AdminUser::ROLE_SUPER_ADMIN);
        $this->save(['brand_name' => 'Amoo'], BrandImages::png());
        $old = (string) $this->settings()[BrandingSettings::LOGO];

        $this->save(['brand_name' => 'Amoo'], BrandImages::png(128, 128));
        $new = (string) $this->settings()[BrandingSettings::LOGO];

        self::assertNotSame($old, $new);
        self::assertFileDoesNotExist($this->directory().'/'.$old);
        self::assertFileExists($this->directory().'/'.$new);
        self::assertCount(1, glob($this->directory().'/*') ?: [], 'one file left');
        $this->client->request('GET', '/brand/logo/'.$old);
        self::assertSame(404, $this->client->getResponse()->getStatusCode());
        $this->client->request('GET', '/brand/logo/'.$new);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        // Saving the form again without a file keeps the logo.
        $this->save(['brand_name' => 'Amoo 2']);
        self::assertSame($new, $this->settings()[BrandingSettings::LOGO]);
        $details = json_decode((string) $this->em->getConnection()->fetchOne('SELECT details FROM admin_audit_log ORDER BY id DESC LIMIT 1'), true);
        self::assertSame('unchanged', $details['logo']);
    }

    public function testRemovingTheLogoDeletesItsFileAndIsAudited(): void
    {
        $this->login(AdminUser::ROLE_SUPER_ADMIN);
        $this->save(['brand_name' => 'Amoo'], BrandImages::png());
        $logo = (string) $this->settings()[BrandingSettings::LOGO];
        $crawler = $this->client->request('GET', '/admin/settings?tab=branding');

        $this->client->submit($crawler->filter('form[action$="/remove-logo"]')->form());

        self::assertTrue($this->client->getResponse()->isRedirect('/admin/settings?tab=branding'));
        self::assertSame('', $this->settings()[BrandingSettings::LOGO]);
        self::assertFileDoesNotExist($this->directory().'/'.$logo);
        self::assertNull($this->branding()->logo);
        $details = json_decode((string) $this->em->getConnection()->fetchOne('SELECT details FROM admin_audit_log ORDER BY id DESC LIMIT 1'), true);
        self::assertSame(['page' => 'branding', 'logo' => 'removed'], $details);
        $this->client->request('GET', '/brand/logo/'.$logo);
        self::assertSame(404, $this->client->getResponse()->getStatusCode());
        $this->client->followRedirects(false);
        $this->client->request('GET', '/admin/settings?tab=branding');
        self::assertStringNotContainsString('/remove-logo', (string) $this->client->getResponse()->getContent(), 'no remove button without a logo');
    }

    public function testOnlySettingsManagersReachTheTab(): void
    {
        $this->login(AdminUser::ROLE_ADMIN);
        foreach ([['GET', '/admin/settings?tab=branding'], ['POST', '/admin/settings?tab=branding'], ['POST', '/admin/settings/branding/remove-logo']] as [$method, $url]) {
            $this->client->request($method, $url, ['brand_name' => 'Hijack']);
            self::assertSame(403, $this->client->getResponse()->getStatusCode(), $method.' '.$url);
        }
        $this->assertNothingSaved();

        $this->client->restart();
        $this->client->request('POST', '/admin/settings/branding/remove-logo');
        self::assertTrue($this->client->getResponse()->isRedirect(), 'anonymous: to the login page');
        self::assertStringContainsString('/admin/login', (string) $this->client->getResponse()->headers->get('Location'));
    }

    public function testTheLogoRouteServesTheCurrentLogoWithLongCacheHeaders(): void
    {
        $this->login(AdminUser::ROLE_SUPER_ADMIN);
        $this->save(['brand_name' => 'Amoo'], BrandImages::png());
        $logo = (string) $this->settings()[BrandingSettings::LOGO];
        $this->client->restart(); // anonymous, like a customer

        $counter = self::getContainer()->get(QueryCounter::class);
        $counter->start();
        $this->client->request('GET', '/brand/logo/'.$logo);
        self::assertSame(1, $counter->stop(), 'one query: the brand settings');

        $response = $this->client->getResponse();
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('image/png', $response->headers->get('Content-Type'));
        self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        self::assertSame("default-src 'none'; sandbox", $response->headers->get('Content-Security-Policy'));
        $cache = (string) $response->headers->get('Cache-Control');
        foreach (['public', 'max-age=31536000', 'immutable'] as $directive) {
            self::assertStringContainsString($directive, $cache);
        }
        $etag = (string) $response->headers->get('ETag');
        self::assertSame('"'.substr($logo, 0, 32).'"', $etag);
        self::assertStringStartsWith('inline', (string) $response->headers->get('Content-Disposition'));
        self::assertNull($response->headers->get('Set-Cookie'), 'no session for a customer');
        ob_start();
        $response->sendContent();
        self::assertSame(file_get_contents($this->directory().'/'.$logo), ob_get_clean());

        $this->client->request('GET', '/brand/logo/'.$logo, [], [], ['HTTP_IF_NONE_MATCH' => $etag]);
        self::assertSame(304, $this->client->getResponse()->getStatusCode());

        $this->client->request('HEAD', '/brand/logo/'.$logo);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
    }

    public function testTheLogoRouteAnswers404ForAnythingButTheCurrentLogo(): void
    {
        $this->client->request('GET', '/brand/logo/'.str_repeat('a', 32).'.png');
        self::assertSame(404, $this->client->getResponse()->getStatusCode(), 'no logo set');
        self::assertSame('nosniff', $this->client->getResponse()->headers->get('X-Content-Type-Options'));

        $this->login(AdminUser::ROLE_SUPER_ADMIN);
        $this->save(['brand_name' => 'Amoo'], BrandImages::png());
        $logo = (string) $this->settings()[BrandingSettings::LOGO];
        // A file in the directory that is not the current logo.
        $other = str_repeat('b', 32).'.png';
        copy($this->directory().'/'.$logo, $this->directory().'/'.$other);

        foreach ([
            '/brand/logo/'.$other,
            '/brand/logo/'.substr($logo, 0, 32).'.jpg',
            '/brand/logo/'.strtoupper($logo),
            '/brand/logo/..%2F..%2F.env',
            '/brand/logo/..%5C..%5C.env',
            '/brand/logo/%2e%2e/%2e%2e/.env',
            '/brand/logo/'.substr($logo, 0, 32).'.svg',
            '/brand/logo/',
        ] as $url) {
            $this->client->request('GET', $url);
            self::assertSame(404, $this->client->getResponse()->getStatusCode(), $url);
        }

        // The file is gone (deleted by hand): 404, not an error.
        unlink($this->directory().'/'.$logo);
        $this->client->request('GET', '/brand/logo/'.$logo);
        self::assertSame(404, $this->client->getResponse()->getStatusCode());
    }

    public function testRowsChangedByHandThatBreakTheRulesAreIgnored(): void
    {
        $this->setRaw(BrandingSettings::NAME, "Evil\u{202E}name");
        $this->setRaw(BrandingSettings::LOGO, '../../.env');
        $this->setRaw(BrandingSettings::ACCENT, 'red;}body{display:none');

        $branding = $this->branding();
        self::assertNull($branding->name);
        self::assertNull($branding->logo);
        self::assertSame('#166534', $branding->accentColor);
    }

    public function testTheButtonTextStaysReadableOnALightAccent(): void
    {
        self::assertSame('#ffffff', (new \App\Branding\Branding(accentColor: '#166534'))->accentText());
        self::assertSame('#ffffff', (new \App\Branding\Branding(accentColor: '#1d4ed8'))->accentText());
        self::assertSame('#111827', (new \App\Branding\Branding(accentColor: '#facc15'))->accentText());
        self::assertSame('#111827', (new \App\Branding\Branding(accentColor: '#ffffff'))->accentText());
    }

    private function login(string $role): void
    {
        $admin = (new AdminUser())->setEmail(strtolower($role).'@brand.test')->setRoles([$role])->setPassword('x')->setIsActive(true);
        $this->em->persist($admin);
        $this->em->flush();
        $this->client->loginUser($admin, 'main');
    }

    /**
     * @param array<string, string> $form
     */
    private function save(array $form, ?string $logo = null, string $logoName = 'logo.png'): void
    {
        $crawler = $this->client->request('GET', '/admin/settings?tab=branding');
        $token = (string) $crawler->filter('form[enctype="multipart/form-data"] input[name="_token"]')->attr('value');
        $files = [];
        if (null !== $logo) {
            $path = $this->work.'/'.bin2hex(random_bytes(6));
            file_put_contents($path, $logo);
            $files['brand_logo'] = new UploadedFile($path, $logoName, 'image/png', null, true);
        }
        $this->client->request('POST', '/admin/settings?tab=branding', $form + ['brand_accent_color' => '', '_token' => $token], $files);
    }

    /**
     * @param array<string, string> $parameters
     */
    private function assertFlash(string $key, array $parameters = []): void
    {
        $text = self::getContainer()->get('translator')->trans($key, $parameters, 'admin', 'fa');
        self::assertStringContainsString(htmlspecialchars($text, ENT_QUOTES), (string) $this->client->getResponse()->getContent());
    }

    private function assertNothingSaved(): void
    {
        self::assertSame([], $this->settings(), 'nothing saved');
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM admin_audit_log'));
        self::assertSame([], glob($this->directory().'/*') ?: [], 'no file kept');
    }

    /**
     * @return array<string, string>
     */
    private function settings(): array
    {
        return $this->em->getConnection()->fetchAllKeyValue("SELECT key_name, value FROM setting WHERE key_name LIKE 'brand.%'");
    }

    private function setRaw(string $key, string $value): void
    {
        $this->em->getConnection()->executeStatement('INSERT INTO setting (key_name, value, type, created_at) VALUES (?, ?, ?, NOW())', [$key, $value, 'text']);
    }

    private function branding(): \App\Branding\Branding
    {
        $settings = self::getContainer()->get(BrandingSettings::class);
        $settings->reset();

        return $settings->current();
    }

    private function directory(): string
    {
        return (string) self::getContainer()->getParameter('app.branding_dir');
    }
}
