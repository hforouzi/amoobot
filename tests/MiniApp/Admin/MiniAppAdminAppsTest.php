<?php

declare(strict_types=1);

namespace App\Tests\MiniApp\Admin;

use App\Entity\AppDownload;
use App\Entity\ClientApp;
use App\Subscription\Application\StoredFile;
use App\Subscription\Domain\ImportLinkTemplate;
use App\Tests\Support\BotAdminTestTrait;
use App\Tests\Support\IntegrationTestCase;
use App\Tests\Support\MiniAppAdminFixtures;
use App\Tests\Support\MiniAppTestTrait;
use App\Tests\Support\RestoresTables;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Client apps and downloads in the Mini App admin (APPS_MANAGE): list, enable / disable, reorder, edit with the
 * entities' rules (the web forms'): the import link template (no javascript: / data: / file:, a URL placeholder
 * required) with a live preview of the link; downloads' metadata, a new download only as an external http(s) link,
 * counters and SHA-256 shown, files stay on the web.
 */
final class MiniAppAdminAppsTest extends IntegrationTestCase
{
    use BotAdminTestTrait;
    use MiniAppAdminFixtures;
    use MiniAppTestTrait;
    use RestoresTables;

    private ClientApp $hiddify;
    private ClientApp $v2rayng;
    private AppDownload $file;

    protected function setUp(): void
    {
        parent::setUp();
        $this->snapshotTables('client_app', 'app_download');
        $this->clean();
        $this->setUpAdminCast();
        $this->hiddify = (new ClientApp())->setName('Hiddify')->setPlatforms(['android', 'ios'])->setImportUrlTemplate('hiddify://import/{url}#{name}')->setSortOrder(10);
        $this->v2rayng = (new ClientApp())->setName('v2rayNG')->setPlatforms(['android'])->setImportUrlTemplate('v2rayng://install-sub?url={url_encoded}')->setSortOrder(20);
        $this->file = (new AppDownload())->setTitle('Hiddify APK')->setPlatform('android')->setVersion('2.5.7')
            ->attachStoredFile(new StoredFile('stored-secret-name.apk', 'hiddify.apk', 'application/vnd.android.package-archive', 2048, str_repeat('ab', 32)));
        foreach ([$this->hiddify, $this->v2rayng, $this->file] as $entity) {
            $this->em->persist($entity);
        }
        $this->em->flush();
        $this->em->getConnection()->executeStatement('UPDATE app_download SET download_count = 42 WHERE id = ?', [$this->file->getId()]);
        $this->startMiniAppClient();
    }

    protected function tearDown(): void
    {
        $this->restoreTables();
        parent::tearDown();
    }

    /**
     * @return iterable<string, array{string, string|null}>
     */
    public static function templates(): iterable
    {
        yield 'path style' => ['streisand://import/{url}', null];
        yield 'query style' => ['v2rayng://install-sub?url={url_encoded}', null];
        yield 'javascript' => ['javascript:alert({url})', 'client_app.template.forbidden_scheme'];
        yield 'data' => ['data:text/html,{url}', 'client_app.template.forbidden_scheme'];
        yield 'file' => ['file:///etc/{url}', 'client_app.template.forbidden_scheme'];
        yield 'no placeholder' => ['hiddify://import/sub', 'client_app.template.no_url'];
        yield 'no scheme' => ['import/{url}', 'client_app.template.no_scheme'];
        yield 'quotes' => ['hiddify://import/{url}"onclick', 'client_app.template.invalid_characters'];
    }

    #[DataProvider('templates')]
    public function testTheTemplateIsCheckedAsOnTheWebWithALivePreview(string $template, ?string $problem): void
    {
        self::assertSame($problem, ImportLinkTemplate::problem($template), 'the web form\'s rule (ClientApp::validateTemplate)');
        $session = $this->authenticate(self::ADMIN);
        $check = $this->appPostJson('/app/api/admin/client-apps/check', $session, ['id' => (string) $this->hiddify->getId(), 'name' => 'Hiddify', 'platforms' => ['android'], 'importUrlTemplate' => $template]);
        self::assertSame(200, $this->httpStatus());
        if (null === $problem) {
            self::assertSame([], (array) $check['errors']);
            self::assertSame(ImportLinkTemplate::build($template, 'https://sub.example.com/sub/abc123', 'VPN'), $check['live']['example']);

            return;
        }
        $message = self::getContainer()->get('translator')->trans($problem, [], 'validators', 'fa');
        self::assertSame([$message], ((array) $check['errors'])['importUrlTemplate']);
        self::assertSame('—', $check['live']['example']);

        $this->appPostJson('/app/api/admin/client-apps/'.$this->hiddify->getId(), $session, ['name' => 'Hiddify', 'platforms' => ['android'], 'importUrlTemplate' => $template], self::newKey());
        self::assertSame(422, $this->httpStatus());
        $this->em->clear();
        self::assertSame('hiddify://import/{url}#{name}', $this->em->find(ClientApp::class, $this->hiddify->getId())?->getImportUrlTemplate(), 'nothing saved');
    }

    public function testAClientAppIsEditedToggledAndReordered(): void
    {
        $session = $this->authenticate(self::ADMIN);
        $this->appPostJson('/app/api/admin/client-apps/'.$this->hiddify->getId(), $session, ['name' => 'Hiddify', 'importUrlTemplate' => 'hiddify://import/{url}'], self::newKey());
        self::assertSame(422, $this->httpStatus(), 'a platform is required');

        $answer = $this->appPostJson('/app/api/admin/client-apps/'.$this->hiddify->getId(), $session, ['name' => 'Hiddify Next', 'platforms' => ['android', 'windows'], 'importUrlTemplate' => 'hiddify://import/{url}'], self::newKey());
        self::assertSame(200, $this->httpStatus(), json_encode($answer, JSON_UNESCAPED_UNICODE));
        $this->em->clear();
        $app = $this->em->find(ClientApp::class, $this->hiddify->getId());
        self::assertSame(['Hiddify Next', ['android', 'windows']], [$app?->getName(), $app?->getPlatforms()]);

        $this->confirmedPost('/app/api/admin/client-apps/'.$this->hiddify->getId().'/deactivate', $session);
        self::assertSame(200, $this->httpStatus());
        $this->appPostJson('/app/api/admin/client-apps/'.$this->v2rayng->getId().'/move', $session, ['direction' => 'up'], self::newKey());
        self::assertSame(200, $this->httpStatus());

        $list = new Crawler($this->appGet('/app/admin/apps?lang=en', $session));
        self::assertDoesNotMatchRegularExpression(self::RAW_KEY, strip_tags($list->html()));
        self::assertSame(['v2rayNG', 'Hiddify Next'], $list->filter('[data-client-app-id] .title')->each(static fn (Crawler $c): string => trim($c->text())));
        self::assertSame('0', $list->filter('[data-client-app-id="'.$this->hiddify->getId().'"]')->attr('data-active'));
        $actions = array_column(array_filter($this->auditRows(), static fn (array $r): bool => str_starts_with((string) $r['action'], 'client_app.')), 'source', 'action');
        self::assertSame(['client_app.update' => 'miniapp', 'client_app.deactivate' => 'miniapp', 'client_app.reorder' => 'miniapp'], $actions);
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function links(): iterable
    {
        yield 'https' => ['https://github.com/hiddify/hiddify-app/releases/download/v2.5.7/Hiddify.apk', true];
        yield 'http' => ['http://dl.example.com/app.apk', true];
        yield 'ftp' => ['ftp://dl.example.com/app.apk', false];
        yield 'javascript' => ['javascript:alert(1)', false];
        yield 'data' => ['data:application/octet-stream;base64,AAAA', false];
        yield 'not a url' => ['download here', false];
        yield 'empty' => ['', false];
    }

    #[DataProvider('links')]
    public function testANewDownloadIsAnExternalHttpLinkOnly(string $url, bool $valid): void
    {
        $session = $this->authenticate(self::ADMIN);
        $answer = $this->appPostJson('/app/api/admin/downloads', $session, ['title' => 'Hiddify for Android', 'platform' => 'android', 'version' => '2.5.7', 'externalUrl' => $url, 'type' => 'file'], self::newKey());
        $created = $this->em->getRepository(AppDownload::class)->findOneBy(['title' => 'Hiddify for Android']);
        if (!$valid) {
            self::assertSame(422, $this->httpStatus(), json_encode($answer, JSON_UNESCAPED_UNICODE));
            self::assertArrayHasKey('externalUrl', (array) $answer['errors']);
            self::assertNull($created);

            return;
        }
        self::assertSame(200, $this->httpStatus(), json_encode($answer, JSON_UNESCAPED_UNICODE));
        self::assertSame([AppDownload::TYPE_URL, $url, null], [$created?->getType(), $created?->getExternalUrl(), $created?->getStoredName()], 'always a link, whatever type was sent');
        self::assertContains('app_download.create', array_column($this->auditRows(), 'action'));
    }

    public function testDownloadsShowCountersAndHashAndFilesStayOnTheWeb(): void
    {
        $session = $this->authenticate(self::ADMIN);
        $list = new Crawler($this->appGet('/app/admin/apps?tab=downloads', $session));
        self::assertSame(200, $this->httpStatus());
        $row = $list->filter('[data-download-id="'.$this->file->getId().'"]');
        self::assertSame('42', trim($row->filter('[data-download-count]')->text()));

        $html = $this->appGet('/app/admin/downloads/'.$this->file->getId().'?lang=en', $session);
        self::assertSame(200, $this->httpStatus());
        self::assertDoesNotMatchRegularExpression(self::RAW_KEY, strip_tags($html));
        $page = new Crawler($html);
        self::assertSame(str_repeat('ab', 32), trim($page->filter('[data-sha256]')->text()));
        self::assertCount(1, $page->filter('[data-file-web-only]'));
        self::assertCount(0, $page->filter('input[type="file"], input[name="externalUrl"], a[href*="/admin"]'), 'no upload, no link to the web panel');
        self::assertStringNotContainsString('stored-secret-name', $html);

        $answer = $this->appPostJson('/app/api/admin/downloads/'.$this->file->getId(), $session, ['title' => 'Hiddify APK', 'platform' => 'android', 'version' => '2.6.0', 'description' => 'Fixes', 'externalUrl' => 'https://evil.example/x'], self::newKey());
        self::assertSame(200, $this->httpStatus(), json_encode($answer, JSON_UNESCAPED_UNICODE));
        $this->em->clear();
        $file = $this->em->find(AppDownload::class, $this->file->getId());
        self::assertSame(['2.6.0', 'Fixes', AppDownload::TYPE_FILE, null, 'stored-secret-name.apk'], [$file?->getVersion(), $file?->getDescription(), $file?->getType(), $file?->getExternalUrl(), $file?->getStoredName()], 'a file stays a file');

        $this->confirmedPost('/app/api/admin/downloads/'.$this->file->getId().'/deactivate', $session);
        self::assertSame(200, $this->httpStatus());
        $this->em->clear();
        self::assertFalse($this->em->find(AppDownload::class, $this->file->getId())?->isActive());
    }

    public function testSupportHasNoAccess(): void
    {
        $session = $this->authenticate(self::SUPPORT);
        $this->appGet('/app/admin/apps', $session);
        self::assertSame(403, $this->httpStatus());
        $this->appPostJson('/app/api/admin/client-apps/'.$this->hiddify->getId().'/move', $session, ['direction' => 'down'], self::newKey());
        self::assertSame(403, $this->httpStatus());
    }

    private function clean(): void
    {
        $this->em->getConnection()->executeStatement('DELETE FROM app_download');
        $this->em->getConnection()->executeStatement('DELETE FROM client_app');
    }
}
