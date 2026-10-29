<?php

declare(strict_types=1);

namespace App\Tests\Reseller\MiniApp;

use App\Entity\ResellerCustomer;
use App\Tests\Support\IntegrationTestCase;
use App\Tests\Support\ResellerMiniAppFixtures;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * A reseller's own customer labels: created, edited and deleted only by him, values stored exactly as typed or refused
 * (never trimmed), shown escaped, and assigned only to services he sold. Every other reseller's label or service is
 * "not found" and stays unchanged.
 */
final class ResellerMiniAppCustomersTest extends IntegrationTestCase
{
    use ResellerMiniAppFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpResellerMiniApp();
    }

    protected function tearDown(): void
    {
        $this->tearDownClaims();
        parent::tearDown();
    }

    public function testCreateEditAssignListAndDelete(): void
    {
        $session = $this->authenticate(self::CUSTOMER);
        $key = self::newKey();
        $body = ['name' => 'علی رضایی', 'phone' => '+989121234567', 'note' => "دو خط\nیادداشت"];
        $created = $this->appPostJson('/app/api/reseller/customers', $session, $body, $key);
        self::assertSame(200, $this->httpStatus(), (string) json_encode($created));
        // A double tap (same key) is one label.
        self::assertSame($created, $this->appPostJson('/app/api/reseller/customers', $session, $body, $key));
        self::assertSame(1, $this->sqlInt('SELECT COUNT(*) FROM reseller_customer'));
        $row = $this->em->getConnection()->fetchAssociative('SELECT id, reseller_id, name, phone, note FROM reseller_customer') ?: [];
        self::assertSame([(int) $this->reseller->getId(), 'علی رضایی', '+989121234567', "دو خط\nیادداشت"], [(int) $row['reseller_id'], $row['name'], $row['phone'], $row['note']]);
        $id = (int) $row['id'];
        self::assertSame('/app/view/reseller/customers/'.$id, $created['redirect']);

        $assigned = $this->appPostJson('/app/api/reseller/services/'.$this->sold->getId().'/customer', $session, ['customerId' => (string) $id]);
        self::assertSame(200, $this->httpStatus(), (string) json_encode($assigned));
        $list = $this->appGet('/app/view/reseller/customers', $session);
        self::assertStringContainsString('data-customer-id="'.$id.'" data-services="1"', $list);
        self::assertStringContainsString('علی رضایی', $this->appGet('/app/view/reseller/services', $session), 'the label shows in the list');
        $filtered = $this->appGet('/app/view/reseller/services?customer='.$id, $session);
        self::assertStringContainsString('data-service-id="'.$this->sold->getId().'"', $filtered);
        self::assertStringContainsString('data-service-id="'.$this->sold->getId().'"', $this->appGet('/app/view/reseller/customers/'.$id, $session));

        $this->appPostJson('/app/api/reseller/customers/'.$id, $session, ['name' => 'Ali R.', 'phone' => '', 'note' => '']);
        self::assertSame(200, $this->httpStatus());
        self::assertSame(['Ali R.', null, null], array_values($this->em->getConnection()->fetchAssociative('SELECT name, phone, note FROM reseller_customer WHERE id = ?', [$id]) ?: []));

        $this->appPostJson('/app/api/reseller/services/'.$this->sold->getId().'/customer', $session, ['customerId' => '']);
        self::assertSame(0, $this->sqlInt('SELECT COUNT(*) FROM reseller_customer_service'));
        $this->appPostJson('/app/api/reseller/services/'.$this->sold->getId().'/customer', $session, ['customerId' => (string) $id]);

        $this->appPostJson('/app/api/reseller/customers/'.$id.'/delete', $session);
        self::assertSame(200, $this->httpStatus());
        self::assertSame(0, $this->sqlInt('SELECT COUNT(*) FROM reseller_customer'));
        self::assertSame(0, $this->sqlInt('SELECT COUNT(*) FROM reseller_customer_service'), 'its assignments went with it');
        self::assertSame('active', $this->em->getConnection()->fetchOne('SELECT status FROM vpn_service WHERE id = ?', [$this->sold->getId()]), 'the service is untouched');
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function unreadableBodies(): iterable
    {
        yield 'a form' => ['application/x-www-form-urlencoded', 'customerId='];
        yield 'no content type' => ['', '{}'];
        yield 'malformed' => ['application/json', '{"customerId":'];
        yield 'a lone surrogate' => ['application/json', '{"name":"Ali\ud800"}'];
        yield 'a list' => ['application/json', '[]'];
        yield 'a string' => ['application/json', '"x"'];
        yield 'null' => ['application/json', 'null'];
        yield 'empty' => ['application/json', ''];
    }

    /** L1: a body that is not a JSON object is 400 on every reseller endpoint that reads one, and changes nothing. */
    #[DataProvider('unreadableBodies')]
    public function testAnUnreadableBodyIs400AndNeverUnassigns(string $contentType, string $raw): void
    {
        $session = $this->authenticate(self::CUSTOMER);
        $created = $this->appPostJson('/app/api/reseller/customers', $session, ['name' => 'Ali'], self::newKey());
        $id = (int) substr((string) $created['redirect'], strlen('/app/view/reseller/customers/'));
        $this->appPostJson('/app/api/reseller/services/'.$this->sold->getId().'/customer', $session, ['customerId' => (string) $id]);
        self::assertSame(1, $this->sqlInt('SELECT COUNT(*) FROM reseller_customer_service'));
        $sold = (string) $this->sold->getId();
        $orders = $this->sqlInt('SELECT COUNT(*) FROM `order`');

        foreach (['/services/'.$sold.'/customer', '/customers', '/customers/'.$id, '/new/quote', '/new', '/services/'.$sold.'/add-traffic/quote', '/services/'.$sold.'/add-traffic'] as $path) {
            $server = ['HTTP_AUTHORIZATION' => 'Bearer '.$session['token'], 'HTTP_X_CSRF_TOKEN' => $session['csrf'], 'HTTP_IDEMPOTENCY_KEY' => self::newKey()];
            if ('' !== $contentType) {
                $server['CONTENT_TYPE'] = $contentType;
            }
            $this->client->request('POST', '/app/api/reseller'.$path, [], [], $server, $raw);
            self::assertSame(400, $this->httpStatus(), $path.': '.$this->client->getResponse()->getContent());
            self::assertSame('bad_request', $this->json()['error'] ?? null, $path);
        }

        self::assertSame(1, $this->sqlInt('SELECT COUNT(*) FROM reseller_customer_service'), 'still assigned');
        self::assertSame(1, $this->sqlInt('SELECT COUNT(*) FROM reseller_customer'));
        self::assertSame('Ali', $this->em->getConnection()->fetchOne('SELECT name FROM reseller_customer'));
        self::assertSame($orders, $this->sqlInt('SELECT COUNT(*) FROM `order`'), 'nothing bought');
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function invalidLabels(): iterable
    {
        yield 'no name' => [['phone' => '0912'], 'name'];
        yield 'leading space' => [['name' => ' Ali'], 'name'];
        yield 'trailing space' => [['name' => 'Ali '], 'name'];
        yield 'trailing newline' => [['name' => "Ali\n"], 'name'];
        yield 'newline inside a name' => [['name' => "Al\ni"], 'name'];
        yield 'tab' => [['name' => "Al\ti"], 'name'];
        yield 'control character' => [['name' => "Al\x07i"], 'name'];
        yield 'bidi override' => [['name' => "Ali\u{202E}gpj"], 'name'];
        // L5: every character of Unicode categories Cc, Cf, Zl and Zp (except the ZWNJ / ZWJ, and line breaks in a note).
        yield 'Cf zero-width space' => [['name' => "Al\u{200B}i"], 'name'];
        yield 'Cf soft hyphen' => [['name' => "Al\u{00AD}i"], 'name'];
        yield 'Cf word joiner' => [['name' => "Al\u{2060}i"], 'name'];
        yield 'Cf left-to-right mark' => [['name' => "Ali\u{200E}"], 'name'];
        yield 'Cf Arabic letter mark' => [['name' => "علی\u{061C}"], 'name'];
        yield 'Cf byte order mark' => [['name' => "\u{FEFF}Ali"], 'name'];
        yield 'Cf tag character' => [['name' => "Ali\u{E0041}"], 'name'];
        yield 'Cc escape' => [['name' => "Ali\u{001B}[31m"], 'name'];
        yield 'Cc C1 control' => [['name' => "Ali\u{0085}x"], 'name'];
        yield 'Zl line separator' => [['name' => "Ali\u{2028}x"], 'name'];
        yield 'Zp paragraph separator in a note' => [['name' => 'Ali', 'note' => "a\u{2029}b"], 'note'];
        yield 'Cc tab in a note' => [['name' => 'Ali', 'note' => "a\tb"], 'note'];
        yield 'Cf zero-width space in a note' => [['name' => 'Ali', 'note' => "a\u{200B}b"], 'note'];
        yield 'Cc in a phone' => [['name' => 'Ali', 'phone' => "0912\u{0000}1234567"], 'phone'];
        yield 'too long' => [['name' => str_repeat('a', 65)], 'name'];
        yield 'not a string' => [['name' => ['Ali']], 'name'];
        yield 'number' => [['name' => 12], 'name'];
        yield 'phone letters' => [['name' => 'Ali', 'phone' => '0912abc'], 'phone'];
        yield 'phone space' => [['name' => 'Ali', 'phone' => '0912 123'], 'phone'];
        yield 'phone newline' => [['name' => 'Ali', 'phone' => "09121234567\n"], 'phone'];
        yield 'phone too short' => [['name' => 'Ali', 'phone' => '123'], 'phone'];
        yield 'note too long' => [['name' => 'Ali', 'note' => str_repeat('x', 256)], 'note'];
        yield 'note trailing newline' => [['name' => 'Ali', 'note' => "x\n"], 'note'];
    }

    /**
     * @param array<string, mixed> $body
     */
    #[DataProvider('invalidLabels')]
    public function testValuesAreRefusedNotRewritten(array $body, string $field): void
    {
        $session = $this->authenticate(self::CUSTOMER);
        $raw = json_encode($body, JSON_THROW_ON_ERROR);
        $this->client->request('POST', '/app/api/reseller/customers', [], [], ['HTTP_AUTHORIZATION' => 'Bearer '.$session['token'], 'HTTP_X_CSRF_TOKEN' => $session['csrf'], 'CONTENT_TYPE' => 'application/json', 'HTTP_IDEMPOTENCY_KEY' => self::newKey()], $raw);
        self::assertSame(422, $this->httpStatus(), (string) $this->client->getResponse()->getContent());
        self::assertArrayHasKey($field, $this->json()['errors'] ?? []);
        self::assertSame(0, $this->sqlInt('SELECT COUNT(*) FROM reseller_customer'));
    }

    public function testPersianTextWithTheZeroWidthNonJoinerAndPersianDigitsIsKeptAsTyped(): void
    {
        $session = $this->authenticate(self::CUSTOMER);
        $this->appPostJson('/app/api/reseller/customers', $session, ['name' => "می\u{200C}خواهم", 'phone' => '۰۹۱۲۱۲۳۴۵۶۷'], self::newKey());
        self::assertSame(200, $this->httpStatus());
        self::assertSame(["می\u{200C}خواهم", '۰۹۱۲۱۲۳۴۵۶۷'], array_values($this->em->getConnection()->fetchAssociative('SELECT name, phone FROM reseller_customer') ?: []));
    }

    /** L5: an invisible or control character gets its own, clear error; nothing is stripped and saved instead. */
    public function testAnInvisibleCharacterIsRefusedWithAClearErrorNotStripped(): void
    {
        $session = $this->authenticate(self::CUSTOMER);
        $answer = $this->appPostJson('/app/api/reseller/customers', $session, ['name' => "Al\u{200B}i"], self::newKey());

        self::assertSame(422, $this->httpStatus());
        $message = self::getContainer()->get('translator')->trans('reseller.customer.error.control', [], 'miniapp', 'fa');
        self::assertNotSame('reseller.customer.error.control', $message, 'translated');
        self::assertSame([$message], $answer['errors']['name'] ?? null);
        self::assertSame(0, $this->sqlInt('SELECT COUNT(*) FROM reseller_customer'));
    }

    /** L5: the two allowed joiners and a note's line breaks are kept exactly as typed. */
    public function testJoinersAndANotesLineBreaksAreKept(): void
    {
        $session = $this->authenticate(self::CUSTOMER);
        $name = "می\u{200C}خواهم 👨\u{200D}👩\u{200D}👧";
        $this->appPostJson('/app/api/reseller/customers', $session, ['name' => $name, 'note' => "line 1\r\nline 2\nline 3"], self::newKey());

        self::assertSame(200, $this->httpStatus());
        self::assertSame([$name, "line 1\r\nline 2\nline 3"], array_values($this->em->getConnection()->fetchAssociative('SELECT name, note FROM reseller_customer') ?: []));
    }

    public function testOutputIsEscaped(): void
    {
        $session = $this->authenticate(self::CUSTOMER);
        $answer = $this->appPostJson('/app/api/reseller/customers', $session, ['name' => '<script>alert(1)</script>', 'note' => '"><img src=x onerror=alert(1)>'], self::newKey());
        $id = (int) preg_replace('/\D/', '', (string) $answer['redirect']);
        $this->appPostJson('/app/api/reseller/services/'.$this->sold->getId().'/customer', $session, ['customerId' => (string) $id]);
        foreach (['/app/view/reseller/customers', '/app/view/reseller/customers/'.$id, '/app/view/reseller/customers/'.$id.'/edit', '/app/view/reseller/services', '/app/view/reseller/services/'.$this->sold->getId()] as $page) {
            $html = $this->appGet($page, $session);
            self::assertStringNotContainsString('<script>alert(1)</script>', $html, $page);
            self::assertStringNotContainsString('<img src=x', $html, $page);
        }
    }

    public function testAnotherResellersLabelsAndServicesAreNotFound(): void
    {
        $others = new ResellerCustomer($this->otherReseller, 'Not yours', '0912000', 'secret note', new \DateTimeImmutable());
        $this->em->persist($others);
        $this->em->flush();
        $otherId = (int) $others->getId();
        $session = $this->authenticate(self::CUSTOMER);
        $mine = $this->appPostJson('/app/api/reseller/customers', $session, ['name' => 'Mine'], self::newKey());
        $mineId = (int) preg_replace('/\D/', '', (string) $mine['redirect']);

        foreach (['/app/view/reseller/customers/'.$otherId, '/app/view/reseller/customers/'.$otherId.'/edit', '/app/view/reseller/services?customer='.$otherId] as $page) {
            $this->appGet($page, $session);
            self::assertSame(404, $this->httpStatus(), $page);
        }
        self::assertStringNotContainsString('Not yours', $this->appGet('/app/view/reseller/customers', $session));
        $this->appPostJson('/app/api/reseller/customers/'.$otherId, $session, ['name' => 'Taken']);
        self::assertSame(404, $this->httpStatus());
        $this->appPostJson('/app/api/reseller/customers/'.$otherId.'/delete', $session);
        self::assertSame(404, $this->httpStatus());
        // His service to another's label, another's service to his label: both refused.
        $this->appPostJson('/app/api/reseller/services/'.$this->sold->getId().'/customer', $session, ['customerId' => (string) $otherId]);
        self::assertSame(404, $this->httpStatus());
        $this->appPostJson('/app/api/reseller/services/'.$this->othersService->getId().'/customer', $session, ['customerId' => (string) $mineId]);
        self::assertSame(404, $this->httpStatus());
        $this->appPostJson('/app/api/reseller/services/'.$this->othersService->getId().'/customer', $session, ['customerId' => '']);
        self::assertSame(404, $this->httpStatus());
        foreach (["$mineId\n", ' '.$mineId, '0', '-1', '1e2'] as $bad) {
            $this->appPostJson('/app/api/reseller/services/'.$this->sold->getId().'/customer', $session, ['customerId' => $bad]);
            self::assertSame(404, $this->httpStatus(), json_encode($bad) ?: '');
        }

        self::assertSame(['Not yours', '0912000', 'secret note'], array_values($this->em->getConnection()->fetchAssociative('SELECT name, phone, note FROM reseller_customer WHERE id = ?', [$otherId]) ?: []));
        self::assertSame(0, $this->sqlInt('SELECT COUNT(*) FROM reseller_customer_service'));
    }
}
