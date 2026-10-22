<?php

declare(strict_types=1);

namespace App\Tests\Admin;

use App\Entity\AdminUser;
use App\Entity\BroadcastDelivery;
use App\Entity\BroadcastMessage;
use App\Tests\Support\IntegrationTestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\DomCrawler\Field\ChoiceFormField;

/**
 * The web broadcast form against Telegram's formatting rules (the shared BroadcastFormat): plain text by default with
 * help under the format, MarkdownV2 / HTML that would not parse refused on the body, "escape automatically" on save;
 * and the duplicate / retry actions on a broadcast that stopped because of a format error.
 */
final class BroadcastWebFormatTest extends IntegrationTestCase
{
    private const FORM = 'BroadcastMessage';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clean();
        $this->em->getConnection()->executeStatement('DELETE FROM admin_audit_log');
        $this->em->getConnection()->executeStatement('DELETE FROM admin_user');
        $admin = (new AdminUser())->setEmail('broadcasts@example.test')->setPassword('unused')->setRoles([AdminUser::ROLE_ADMIN]);
        $this->em->persist($admin);
        $this->em->flush();
        $this->client = new KernelBrowser(self::$kernel);
        $this->client->disableReboot();
        $this->client->loginUser($admin, 'main');
    }

    protected function tearDown(): void
    {
        $this->clean();
        parent::tearDown();
    }

    public function testTheFormatIsPlainByDefaultWithHelp(): void
    {
        foreach (['en', 'fa'] as $locale) {
            $this->client->request('GET', '/admin/locale/'.$locale);
            $crawler = $this->client->request('GET', '/admin/broadcast-message/new');
            self::assertSame(200, $this->client->getResponse()->getStatusCode());
            $select = $crawler->filter('select[name="'.self::FORM.'[parseMode]"]');
            self::assertSame('', (string) $select->filter('option[selected]')->attr('value'), 'plain text selected');
            self::assertStringContainsString('MarkdownV2', $crawler->filter('[id="'.self::FORM.'_parseMode_help"], #'.self::FORM.'_parseMode + .form-text, .form-help')->text(''));
            self::assertStringNotContainsString('broadcast.parse_mode_help', (string) $this->client->getResponse()->getContent());
            self::assertStringContainsString('&lt;b&gt;', (string) $this->client->getResponse()->getContent(), 'the example tags shown as text');
            self::assertCount(1, $crawler->filter('input[type="checkbox"][name="'.self::FORM.'[escapeMarkdownV2]"]'), 'escape automatically');
        }

        $this->submit('/admin/broadcast-message/new', ['title' => 'Default', 'body' => 'Version 2.5!', 'targetType' => 'all']);
        self::assertNull($this->stored('Default')?->getParseMode());
    }

    public function testMarkdownV2AndHtmlThatDoNotParseAreRefused(): void
    {
        $this->client->request('GET', '/admin/locale/en');
        $errors = $this->submit('/admin/broadcast-message/new', ['title' => 'Dot', 'body' => 'نسخه 2.5 آماده است', 'parseMode' => 'MarkdownV2', 'targetType' => 'all'], false);
        self::assertStringContainsString('“.” is a reserved character in MarkdownV2', $errors);
        self::assertNull($this->stored('Dot'));

        $errors = $this->submit('/admin/broadcast-message/new', ['title' => 'Tags', 'body' => '<b><i>x</b></i>', 'parseMode' => 'HTML', 'targetType' => 'all'], false);
        self::assertStringContainsString('</b> was found where </i> was expected', $errors);
        self::assertNull($this->stored('Tags'));

        $this->submit('/admin/broadcast-message/new', ['title' => 'Escaped', 'body' => 'نسخه 2\\.5 آماده است', 'parseMode' => 'MarkdownV2', 'targetType' => 'all']);
        self::assertSame('نسخه 2\\.5 آماده است', $this->stored('Escaped')?->getBody());
    }

    public function testEscapeAutomaticallyOnSave(): void
    {
        $plain = "تخفیف ۲۰٪ تا 15.11! نسخه 2.5.1\nhttps://example.com/a_b?x=1";
        $this->submit('/admin/broadcast-message/new', ['title' => 'Auto', 'body' => $plain, 'parseMode' => 'MarkdownV2', 'targetType' => 'all', 'escapeMarkdownV2' => true]);

        self::assertSame("تخفیف ۲۰٪ تا 15\\.11\\! نسخه 2\\.5\\.1\nhttps://example\\.com/a\\_b?x\\=1", $this->stored('Auto')?->getBody());

        // Only for MarkdownV2: plain text is kept as written.
        $this->submit('/admin/broadcast-message/new', ['title' => 'Plain', 'body' => 'Version 2.5!', 'targetType' => 'all', 'escapeMarkdownV2' => true]);
        self::assertSame('Version 2.5!', $this->stored('Plain')?->getBody());
    }

    public function testAStoppedBroadcastIsDuplicatedNotRetried(): void
    {
        $stopped = (new BroadcastMessage())->setTitle('Stopped')->setBody('Version 2.5')->setParseMode('MarkdownV2')->setTargetType('all')
            ->setStatus(BroadcastMessage::STATUS_STOPPED)->setTotalRecipients(4)->setFailedCount(3);
        $this->em->persist($stopped);
        foreach (['1', '2', '3'] as $chat) {
            $this->em->persist((new BroadcastDelivery())->setBroadcast($stopped)->setChatId('9100'.$chat)->setStatus(BroadcastDelivery::STATUS_FAILED)->setErrorMessage("Telegram API returned ok=false on method \"sendMessage\": Bad Request: can't parse entities: Character '.' is reserved"));
        }
        $this->em->flush();

        $detail = $this->client->request('GET', '/admin/broadcast-message/'.$stopped->getId());
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $duplicate = $detail->filter('form[action*="/admin/broadcast/'.$stopped->getId().'/duplicate"]');
        self::assertCount(1, $duplicate, 'the duplicate action');
        self::assertCount(1, $detail->filter('form[action*="/admin/broadcast/'.$stopped->getId().'/retry-failed"]'), 'retry is shown for failed deliveries');

        $this->client->request('POST', '/admin/broadcast/'.$stopped->getId().'/duplicate', ['_token' => 'forged']);
        self::assertSame(1, $this->em->getRepository(BroadcastMessage::class)->count(['title' => 'Stopped']), 'no copy without a valid token');

        $this->client->submit($duplicate->form());
        self::assertTrue($this->client->getResponse()->isRedirect());
        $copy = $this->em->getRepository(BroadcastMessage::class)->findOneBy(['title' => 'Stopped', 'status' => BroadcastMessage::STATUS_DRAFT]);
        self::assertInstanceOf(BroadcastMessage::class, $copy);
        self::assertSame(['Version 2.5', 'MarkdownV2', 'all'], [$copy->getBody(), $copy->getParseMode(), $copy->getTargetType()]);
        self::assertStringContainsString('/admin/broadcast-message/'.$copy->getId().'/edit', (string) $this->client->getResponse()->headers->get('Location'));
        self::assertSame('broadcast.duplicate', $this->em->getConnection()->fetchOne("SELECT action FROM admin_audit_log WHERE action = 'broadcast.duplicate' AND source = 'web'"));

        $this->client->request('GET', '/admin/locale/en');
        $detail = $this->client->request('GET', '/admin/broadcast-message/'.$stopped->getId());
        $this->client->submit($detail->filter('form[action*="/retry-failed"]')->form());
        $this->client->followRedirect();
        self::assertStringContainsString('Not retried', (string) $this->client->getResponse()->getContent());
        self::assertCount(0, $this->http->requests('/sendMessage'));
        self::assertSame(3, $this->em->getRepository(BroadcastDelivery::class)->count(['broadcast' => $stopped->getId(), 'status' => BroadcastDelivery::STATUS_FAILED]));
    }

    /**
     * @param array<string, string|bool> $fields
     *
     * @return string the form's errors when it was refused
     */
    private function submit(string $path, array $fields, bool $saved = true): string
    {
        $crawler = $this->client->request('GET', $path);
        self::assertSame(200, $this->client->getResponse()->getStatusCode(), $path);
        $form = $crawler->filter('form[name="'.self::FORM.'"]')->form();
        foreach ($fields as $name => $value) {
            if (is_bool($value)) {
                $box = $form[self::FORM.'['.$name.']'];
                self::assertInstanceOf(ChoiceFormField::class, $box);
                $value ? $box->tick() : $box->untick();
            } else {
                $form[self::FORM.'['.$name.']'] = $value;
            }
        }
        $this->client->submit($form);
        $errors = implode(' | ', (new Crawler((string) $this->client->getResponse()->getContent()))->filter('.invalid-feedback, .form-error-message')->each(static fn (Crawler $c): string => trim($c->text())));
        self::assertSame($saved, 302 === $this->client->getResponse()->getStatusCode(), $path.': '.$errors);

        return $errors;
    }

    private function stored(string $title): ?BroadcastMessage
    {
        $this->em->clear();

        return $this->em->getRepository(BroadcastMessage::class)->findOneBy(['title' => $title]);
    }

    private function clean(): void
    {
        $this->em->getConnection()->executeStatement('DELETE FROM broadcast_delivery');
        $this->em->getConnection()->executeStatement('DELETE FROM broadcast_message');
    }
}
