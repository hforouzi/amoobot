<?php

declare(strict_types=1);

namespace App\Tests\Subscription;

use App\Entity\AdminUser;
use App\Entity\AppDownload;
use App\Entity\ClientApp;
use App\Entity\VpnService;
use App\Subscription\Application\AppDownloadRejected;
use App\Subscription\Application\AppDownloadStorage;
use App\Tests\Support\BotAdminTestTrait;
use App\Tests\Support\IntegrationTestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Field\FileFormField;
use Symfony\Component\DomCrawler\Form;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * App downloads managed by admins: upload validation (extension, size, content type), storage outside public/, the
 * /d/{id} endpoint (headers, Range, counters, external URLs, rate limit), the admin form, the page and the bot.
 */
final class AppDownloadTest extends IntegrationTestCase
{
    use BotAdminTestTrait;

    private KernelBrowser $client;
    private string $work;

    protected function setUp(): void
    {
        parent::setUp();
        $connection = $this->em->getConnection();
        $connection->executeStatement('DELETE FROM app_download');
        $connection->executeStatement('DELETE FROM admin_audit_log');
        $connection->executeStatement('DELETE FROM admin_user');
        $this->client = new KernelBrowser(self::$kernel);
        $this->client->disableReboot();
        $this->client->setServerParameter('REMOTE_ADDR', '10.80.'.random_int(0, 255).'.'.random_int(1, 254));
        $this->work = sys_get_temp_dir().'/amoobot-dl-test-'.bin2hex(random_bytes(4));
        mkdir($this->work);
    }

    protected function tearDown(): void
    {
        foreach ($this->em->getRepository(AppDownload::class)->findAll() as $download) {
            $this->storage()->delete($download);
        }
        $this->em->getConnection()->executeStatement('DELETE FROM app_download');
        foreach (glob($this->work.'/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->work);
        parent::tearDown();
    }

    public function testAnUploadIsStoredOutsidePublicUnderARandomNameWithItsSha256(): void
    {
        $content = $this->zip();
        $download = $this->upload('My VPN (v1.2) ☺.apk', $content);

        $path = $this->storage()->path($download);
        $projectDir = (string) self::getContainer()->getParameter('kernel.project_dir');
        self::assertFileExists($path);
        self::assertStringStartsNotWith($projectDir.'/public', realpath($path) ?: $path, 'never under public/');
        self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/', (string) $download->getStoredName(), 'random storage name');
        self::assertSame(hash('sha256', $content), $download->getSha256());
        self::assertSame(strlen($content), $download->getSizeBytes());
        self::assertSame('My-VPN-v1.2.apk', $download->getOriginalName(), 'sanitized original name');
        self::assertSame('application/vnd.android.package-archive', $download->getMimeType());
    }

    public function testOnlyAllowListedExtensionsAreAccepted(): void
    {
        foreach (['shell.php', 'page.html', 'icon.svg', 'evil.apk.php', 'noext', 'script.js', 'x.phar', '.htaccess'] as $name) {
            $this->assertRejected($name, $this->zip(), 'app_download.upload.extension');
        }
        foreach (['a.apk', 'b.zip', 'c.exe', 'd.msi', 'e.dmg', 'f.pkg', 'g.AppImage', 'h.deb'] as $name) {
            try {
                $this->upload($name, $this->binaryFor($name));
            } catch (AppDownloadRejected $e) {
                self::fail($name.' rejected: '.$e->getMessage().' '.json_encode($e->parameters));
            }
        }
        self::assertSame(8, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM app_download'));
    }

    public function testTheSizeLimitIsEnforced(): void
    {
        $storage = self::getContainer()->get(AppDownloadStorage::class)->withMaxBytes(1024);

        $this->expectExceptionObject(new AppDownloadRejected('app_download.upload.too_large'));
        $storage->store($this->file('big.zip', $this->zip(2048)));
    }

    public function testTheDefaultSizeLimitIs200Mb(): void
    {
        self::assertSame(200 * 1024 * 1024, self::getContainer()->get(AppDownloadStorage::class)->maxBytes());
    }

    public function testTheContentMustMatchTheExtension(): void
    {
        $this->assertRejected('app.apk', "<?php system(\$_GET['c']);", 'app_download.upload.content_type');
        $this->assertRejected('setup.exe', '<html><script>alert(1)</script></html>', 'app_download.upload.content_type');
        $this->assertRejected('app.zip', (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg=='), 'app_download.upload.content_type');
        $this->assertRejected('empty.zip', '', 'app_download.upload.empty');
    }

    public function testADownloadIsServedAsAnAttachmentAndCounted(): void
    {
        $content = $this->zip(5000);
        $download = $this->upload('client.apk', $content);

        $this->client->request('GET', '/d/'.$download->getId());
        $response = $this->client->getResponse();

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('application/vnd.android.package-archive', $response->headers->get('Content-Type'));
        self::assertStringContainsString('attachment; filename=client.apk', (string) $response->headers->get('Content-Disposition'));
        self::assertSame('bytes', $response->headers->get('Accept-Ranges'));
        self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        self::assertStringContainsString('noindex', (string) $response->headers->get('X-Robots-Tag'));
        self::assertSame($content, $this->body());
        self::assertSame(1, $this->downloadCount($download));
    }

    public function testRangeRequestsResumeLargeFilesAndCountOnlyTheStart(): void
    {
        $content = $this->zip(10000);
        $download = $this->upload('client.apk', $content);

        $this->client->request('GET', '/d/'.$download->getId(), [], [], ['HTTP_RANGE' => 'bytes=100-199']);
        $response = $this->client->getResponse();
        self::assertSame(206, $response->getStatusCode());
        self::assertSame('bytes 100-199/'.strlen($content), $response->headers->get('Content-Range'));
        self::assertSame(substr($content, 100, 100), $this->body());
        self::assertSame(0, $this->downloadCount($download), 'a resumed download is not a new one');

        $this->client->request('GET', '/d/'.$download->getId(), [], [], ['HTTP_RANGE' => 'bytes=0-']);
        self::assertSame(206, $this->client->getResponse()->getStatusCode());
        self::assertSame(1, $this->downloadCount($download), 'a download from the start counts');

        // An unsatisfiable range is ignored (RFC 9110 allows it): the whole file, counted as a download.
        $this->client->request('GET', '/d/'.$download->getId(), [], [], ['HTTP_RANGE' => 'bytes=999999-']);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertSame(2, $this->downloadCount($download));

        $this->client->request('HEAD', '/d/'.$download->getId());
        self::assertSame(2, $this->downloadCount($download), 'HEAD does not count');
    }

    public function testAnExternalDownloadRedirectsAndIsCounted(): void
    {
        $download = $this->external('https://github.com/2dust/v2rayNG/releases');

        $this->client->request('GET', '/d/'.$download->getId());

        self::assertSame(302, $this->client->getResponse()->getStatusCode());
        self::assertSame('https://github.com/2dust/v2rayNG/releases', $this->client->getResponse()->headers->get('Location'));
        self::assertSame(1, $this->downloadCount($download));
    }

    public function testExternalUrlsMustBeHttpOrHttps(): void
    {
        $validator = self::getContainer()->get(ValidatorInterface::class);
        foreach (['javascript:alert(1)', 'ftp://files.example.com/a.apk', 'file:///etc/passwd', 'data:text/html,x', '//evil.example.com/a', 'not a url'] as $url) {
            $download = (new AppDownload())->setTitle('X')->setPlatform('android')->setType(AppDownload::TYPE_URL)->setExternalUrl($url);
            self::assertGreaterThan(0, count($validator->validate($download)), $url);
        }
        $download = (new AppDownload())->setTitle('X')->setPlatform('android')->setType(AppDownload::TYPE_URL)->setExternalUrl('https://github.com/a/b/releases');
        self::assertCount(0, $validator->validate($download));
        $download = (new AppDownload())->setTitle('X')->setPlatform('android')->setType(AppDownload::TYPE_URL);
        self::assertGreaterThan(0, count($validator->validate($download)), 'a link download needs its URL');
    }

    public function testInactiveUnknownAndMissingDownloadsAre404(): void
    {
        $inactive = $this->upload('client.apk', $this->zip())->setIsActive(false);
        $missing = $this->upload('other.apk', $this->zip());
        $this->em->flush();
        unlink($this->storage()->path($missing));

        foreach (['/d/'.$inactive->getId(), '/d/'.$missing->getId(), '/d/999999', '/d/abc'] as $path) {
            $this->client->request('GET', $path);
            self::assertSame(404, $this->client->getResponse()->getStatusCode(), $path);
        }
        self::assertSame(0, $this->downloadCount($inactive));
    }

    public function testDownloadsAreRateLimitedPerIp(): void
    {
        $download = $this->external('https://github.com/a/b/releases');
        for ($i = 0; $i < 60; ++$i) {
            $this->client->request('GET', '/d/'.$download->getId());
        }
        self::assertSame(302, $this->client->getResponse()->getStatusCode());

        $this->client->request('GET', '/d/'.$download->getId());
        self::assertSame(429, $this->client->getResponse()->getStatusCode());
        self::assertSame(60, $this->downloadCount($download));
    }

    public function testAnAdminUploadsThroughTheFormAndItIsAudited(): void
    {
        $this->client->loginUser($this->admin(AdminUser::ROLE_ADMIN), 'main');
        $crawler = $this->client->request('GET', '/admin/apps/downloads/new');
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $form = $crawler->filter('form[name="AppDownload"]')->form();
        $form['AppDownload[title]'] = 'Hiddify for Android';
        $form['AppDownload[platform]'] = 'android';
        $form['AppDownload[version]'] = '2.5.7';
        $form['AppDownload[type]'] = AppDownload::TYPE_FILE;
        $path = $this->work.'/hiddify.apk';
        file_put_contents($path, $this->zip());
        $this->fileField($form)->upload($path);
        $this->client->submit($form);

        self::assertSame(302, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());
        $this->em->clear();
        $download = $this->em->getRepository(AppDownload::class)->findOneBy(['title' => 'Hiddify for Android']);
        self::assertInstanceOf(AppDownload::class, $download);
        self::assertSame(hash_file('sha256', $path), $download->getSha256());
        self::assertFileExists($this->storage()->path($download));
        self::assertSame(['app_download.create'], $this->em->getConnection()->fetchFirstColumn('SELECT action FROM admin_audit_log'));

        // The detail page shows the sha256.
        $this->client->request('GET', '/admin/apps/downloads/'.$download->getId());
        self::assertStringContainsString((string) $download->getSha256(), (string) $this->client->getResponse()->getContent());
    }

    public function testTheFormRejectsABadFileWithAMessage(): void
    {
        $this->client->loginUser($this->admin(AdminUser::ROLE_SUPER_ADMIN), 'main');
        $crawler = $this->client->request('GET', '/admin/apps/downloads/new');
        $form = $crawler->filter('form[name="AppDownload"]')->form();
        $form['AppDownload[title]'] = 'Bad';
        $form['AppDownload[platform]'] = 'android';
        $form['AppDownload[type]'] = AppDownload::TYPE_FILE;
        $path = $this->work.'/shell.php';
        file_put_contents($path, '<?php echo 1;');
        $this->fileField($form)->upload($path);
        $this->client->submit($form);

        self::assertSame(422, $this->client->getResponse()->getStatusCode());
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM app_download'));
    }

    public function testSupportCannotManageDownloads(): void
    {
        $this->client->loginUser($this->admin(AdminUser::ROLE_SUPPORT), 'main');
        foreach (['/admin/apps/downloads', '/admin/apps/downloads/new', '/admin/apps/clients'] as $path) {
            $this->client->request('GET', $path);
            self::assertSame(403, $this->client->getResponse()->getStatusCode(), $path);
        }
    }

    public function testDeletingADownloadRemovesItsFile(): void
    {
        $download = $this->upload('client.apk', $this->zip());
        $path = $this->storage()->path($download);
        $this->client->loginUser($this->admin(AdminUser::ROLE_ADMIN), 'main');

        $crawler = $this->client->request('GET', '/admin/apps/downloads');
        $token = (string) $crawler->filter('form#action-confirmation-form input[name="token"]')->attr('value');
        $this->client->request('POST', '/admin/apps/downloads/'.$download->getId().'/delete', ['token' => $token]);

        self::assertSame(302, $this->client->getResponse()->getStatusCode());
        self::assertFileDoesNotExist($path);
    }

    public function testThePageListsDownloadsForTheVisitorsPlatformAndUsesThemAsInstallLinks(): void
    {
        $hiddify = $this->em->getRepository(ClientApp::class)->findOneBy(['name' => 'Hiddify']);
        $apk = $this->upload('hiddify.apk', $this->zip())->setTitle('Hiddify APK')->setClientApp($hiddify);
        $this->upload('setup.exe', $this->binaryFor('setup.exe'))->setTitle('Windows setup')->setPlatform('windows');
        $this->em->flush();
        $service = $this->service();

        $this->client->request('GET', '/s/'.$service->getPublicToken(), [], [], ['HTTP_USER_AGENT' => 'Mozilla/5.0 (Linux; Android 14)']);
        $html = (string) $this->client->getResponse()->getContent();

        self::assertStringContainsString('Hiddify APK', $html);
        self::assertStringContainsString((string) $apk->getSha256(), $html, 'sha256 shown');
        self::assertStringContainsString('href="/d/'.$apk->getId().'"', $html, 'install link of the app is its download');
        self::assertLessThan(strpos($html, 'Windows setup'), strpos($html, 'Hiddify APK'), 'visitor platform first');
    }

    public function testTheBotListsActiveDownloadsByPlatform(): void
    {
        self::getContainer()->get(RouterInterface::class)->getContext()->setScheme('https')->setHost('shop.example.com');
        $android = $this->upload('client.apk', $this->zip())->setTitle('Android client');
        $this->upload('setup.exe', $this->binaryFor('setup.exe'))->setTitle('Windows client')->setPlatform('windows');
        $this->upload('old.apk', $this->zip())->setTitle('Old client')->setIsActive(false);
        $this->em->flush();
        $this->createUser('7911');
        $this->http->reset();

        $this->sendText('7911', $this->botButton('button.main.downloads'));

        $buttons = $this->urlButtons();
        self::assertContains('https://shop.example.com/d/'.$android->getId(), array_column($buttons, 'url'));
        self::assertContains('Windows client', array_map(static fn (array $b): string => (string) preg_replace('/^\S+\s/u', '', $b['text']), $buttons));
        self::assertNotContains('Old client', array_map(static fn (array $b): string => (string) preg_replace('/^\S+\s/u', '', $b['text']), $buttons));
    }

    public function testTheMainKeyboardHasTheDownloadsButtonOnlyWhenThereAreDownloads(): void
    {
        $this->createUser('7912');
        $this->sendText('7912', '/start');
        self::assertFalse($this->replyKeyboardHas($this->botButton('button.main.downloads')));

        $this->external('https://github.com/a/b/releases');
        $this->http->reset();
        $this->sendText('7912', '/start');
        self::assertTrue($this->replyKeyboardHas($this->botButton('button.main.downloads')));
    }

    private function fileField(Form $form): FileFormField
    {
        $field = $form['AppDownload[uploadedFile]'];
        self::assertInstanceOf(FileFormField::class, $field);

        return $field;
    }

    private function storage(): AppDownloadStorage
    {
        return self::getContainer()->get(AppDownloadStorage::class);
    }

    private function upload(string $name, string $content): AppDownload
    {
        $stored = $this->storage()->store($this->file($name, $content));
        $download = (new AppDownload())->setTitle($name)->setPlatform('android')->setType(AppDownload::TYPE_FILE)->attachStoredFile($stored);
        $this->em->persist($download);
        $this->em->flush();

        return $download;
    }

    private function external(string $url): AppDownload
    {
        $download = (new AppDownload())->setTitle('External')->setPlatform('android')->setType(AppDownload::TYPE_URL)->setExternalUrl($url);
        $this->em->persist($download);
        $this->em->flush();

        return $download;
    }

    private function assertRejected(string $name, string $content, string $reason): void
    {
        try {
            $this->storage()->store($this->file($name, $content));
            self::fail($name.' was accepted');
        } catch (AppDownloadRejected $e) {
            self::assertSame($reason, $e->getMessage(), $name);
        }
    }

    private function file(string $name, string $content): UploadedFile
    {
        $path = $this->work.'/'.bin2hex(random_bytes(6));
        file_put_contents($path, $content);

        return new UploadedFile($path, $name, null, null, true);
    }

    /** A real zip archive (what an APK is), padded to about $size bytes. */
    private function zip(int $size = 512): string
    {
        $path = $this->work.'/build-'.bin2hex(random_bytes(4)).'.zip';
        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::CREATE);
        $zip->addFromString('AndroidManifest.xml', random_bytes(max(16, $size)));
        $zip->setCompressionName('AndroidManifest.xml', \ZipArchive::CM_STORE);
        $zip->close();
        $content = (string) file_get_contents($path);
        unlink($path);

        return $content;
    }

    /** Content an installer of this type starts with. */
    private function binaryFor(string $name): string
    {
        return match (strtolower(pathinfo($name, PATHINFO_EXTENSION))) {
            'exe' => 'MZ'.str_repeat("\x90", 58).pack('V', 64)."PE\0\0".str_repeat("\0\xAB", 128),
            'msi' => "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1".str_repeat("\0\xAB", 256),
            'dmg' => str_repeat("\0\xAB", 256).'koly'.str_repeat("\0", 508),
            'pkg' => 'xar!'.str_repeat("\0\xAB", 256),
            'appimage' => "\x7FELF\x02\x01\x01\0".str_repeat("\0\xAB", 256),
            'deb' => "!<arch>\ndebian-binary   ".str_repeat("\0\xAB", 128),
            default => $this->zip(),
        };
    }

    private function body(): string
    {
        ob_start();
        $this->client->getResponse()->sendContent();

        return (string) (ob_get_clean() ?: $this->client->getInternalResponse()->getContent());
    }

    private function downloadCount(AppDownload $download): int
    {
        return (int) $this->em->getConnection()->fetchOne('SELECT download_count FROM app_download WHERE id = ?', [$download->getId()]);
    }

    private function admin(string $role): AdminUser
    {
        $admin = (new AdminUser())->setEmail(strtolower($role).'-dl@shop.test')->setPassword('unused')->setRoles([$role])->setIsActive(true);
        $this->em->persist($admin);
        $this->em->flush();

        return $admin;
    }

    private function service(): VpnService
    {
        $service = $this->createActiveService($this->createUser(), $this->createTestInbound())
            ->setSubscriptionUrl('https://sub.example-shop.com:2096/sub/k3j9x7q2m1zt5w8a');
        $this->em->flush();

        return $service;
    }

    /**
     * @return list<array{text: string, url: string}>
     */
    private function urlButtons(): array
    {
        $buttons = [];
        foreach ($this->http->requests('/sendMessage') as $request) {
            $markup = $this->requestBody($request)['reply_markup'] ?? null;
            $markup = is_string($markup) ? json_decode($markup, true) : $markup;
            foreach ((array) ($markup['inline_keyboard'] ?? []) as $row) {
                foreach ($row as $button) {
                    if (isset($button['url'])) {
                        $buttons[] = ['text' => (string) $button['text'], 'url' => (string) $button['url']];
                    }
                }
            }
        }

        return $buttons;
    }

    private function replyKeyboardHas(string $label): bool
    {
        foreach ($this->http->requests('/sendMessage') as $request) {
            $markup = $this->requestBody($request)['reply_markup'] ?? null;
            $markup = is_string($markup) ? json_decode($markup, true) : $markup;
            foreach ((array) ($markup['keyboard'] ?? []) as $row) {
                foreach ($row as $button) {
                    if ($label === ($button['text'] ?? null)) {
                        return true;
                    }
                }
            }
        }

        return false;
    }
}
