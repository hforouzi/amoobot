<?php

declare(strict_types=1);

namespace App\Tests\Reseller\MiniApp;

use App\Entity\TelegramAccount;
use App\Tests\Support\IntegrationTestCase;
use App\Tests\Support\ResellerMiniAppFixtures;

/**
 * The services a reseller sold, in the Mini App: the list (filters, search), one service, and D5 after a claim. While
 * the service is still his he gets the owner's tools; once a customer claimed it, no response of the reseller section
 * carries its links, its subscription page, its public token or anything about the claimer (Telegram id, name,
 * username), and the owner screens refuse him. Another reseller's service is "not found" everywhere.
 */
final class ResellerMiniAppServicesTest extends IntegrationTestCase
{
    use ResellerMiniAppFixtures;

    private const CLAIMER = '8701';
    private const CLAIMER_NAME = 'Zohreh';
    private const CLAIMER_USERNAME = 'zohreh_claims';

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

    public function testTheListFiltersAndSearches(): void
    {
        $session = $this->authenticate(self::CUSTOMER);
        $this->buy();
        $this->claimByCustomer();
        $mine = $this->appGet('/app/view/reseller/services?tab=mine', $session);
        $claimed = $this->appGet('/app/view/reseller/services?tab=claimed', $session);
        $all = $this->appGet('/app/view/reseller/services', $session);

        self::assertSame(1, substr_count($mine, 'data-service-id='));
        self::assertStringNotContainsString('data-service-id="'.$this->sold->getId().'"', $mine);
        self::assertSame(1, substr_count($claimed, 'data-service-id='));
        self::assertStringContainsString('data-service-id="'.$this->sold->getId().'" data-status="active" data-claimed', $claimed);
        self::assertSame(2, substr_count($all, 'data-service-id='));
        self::assertStringNotContainsString('data-service-id="'.$this->othersService->getId().'"', $all, 'only his');

        $byId = $this->appGet('/app/view/reseller/services?q=%23'.$this->sold->getId(), $session);
        self::assertStringContainsString('data-service-id="'.$this->sold->getId().'"', $byId);
        $byName = $this->appGet('/app/view/reseller/services?q='.rawurlencode((string) $this->sold->getUsername()), $session);
        self::assertStringContainsString('data-service-id="'.$this->sold->getId().'"', $byName);
        $other = $this->appGet('/app/view/reseller/services?q='.rawurlencode((string) $this->othersService->getUsername()), $session);
        self::assertSame(0, substr_count($other, 'data-service-id='), 'searching never reaches another reseller');
        $wild = $this->appGet('/app/view/reseller/services?q=%25', $session);
        self::assertSame(0, substr_count($wild, 'data-service-id='), '% is matched literally');

        foreach (['?tab=nope', '?page=0', '?page=abc', '?customer=x', '?q=%20a', '?q=a%0A', '?customer=999999'] as $query) {
            $this->appGet('/app/view/reseller/services'.$query, $session);
            self::assertSame(404, $this->httpStatus(), $query);
        }
    }

    public function testWhileStillHisHeGetsTheOwnerTools(): void
    {
        $session = $this->authenticate(self::CUSTOMER);
        $html = $this->appGet('/app/view/reseller/services/'.$this->sold->getId(), $session);
        self::assertSame(200, $this->httpStatus());
        self::assertStringContainsString('data-ownership="mine"', $html);
        self::assertStringContainsString('data-owner-tools', $html);
        self::assertStringContainsString('/app/view/services/'.$this->sold->getId().'/subscription', $html);
        // The owner screens are the customer's own, which check ownership themselves.
        $this->appGet('/app/view/services/'.$this->sold->getId().'/subscription', $session);
        self::assertSame(200, $this->httpStatus());
    }

    public function testAfterAClaimNothingOfTheCustomerOrHisAccessReachesTheReseller(): void
    {
        $session = $this->authenticate(self::CUSTOMER);
        $this->claimByCustomer();
        $service = $this->em->getConnection()->fetchAssociative('SELECT public_token, subscription_url, username FROM vpn_service WHERE id = ?', [$this->sold->getId()]) ?: [];
        $label = $this->appPostJson('/app/api/reseller/customers', $session, ['name' => 'My label'], self::newKey());
        $this->appPostJson('/app/api/reseller/services/'.$this->sold->getId().'/customer', $session, ['customerId' => (string) ((int) preg_replace('/\D/', '', (string) $label['redirect']))]);

        $pages = [
            '/app/view/reseller',
            '/app/view/reseller/services',
            '/app/view/reseller/services?tab=claimed',
            '/app/view/reseller/services/'.$this->sold->getId(),
            '/app/view/reseller/services/'.$this->sold->getId().'/renew',
            '/app/view/reseller/customers',
            (string) $label['redirect'],
            '/app/view/reseller/links',
        ];
        foreach ($pages as $page) {
            $html = $this->appGet($page, $session);
            self::assertSame(200, $this->httpStatus(), $page);
            foreach ([self::CLAIMER, self::CLAIMER_NAME, self::CLAIMER_USERNAME, (string) $service['public_token'], '/s/'] as $secret) {
                self::assertStringNotContainsString($secret, $html, $page.' leaks '.$secret);
            }
            if ('' !== (string) $service['subscription_url']) {
                self::assertStringNotContainsString((string) $service['subscription_url'], $html, $page);
            }
            self::assertStringNotContainsString('data-owner-tools', $html, $page);
            self::assertStringNotContainsString('/subscription"', $html, $page);
        }
        $detail = $this->appGet('/app/view/reseller/services/'.$this->sold->getId(), $session);
        self::assertStringContainsString('data-ownership="claimed"', $detail);
        self::assertStringContainsString('data-claimed-note', $detail);
        self::assertStringNotContainsString('data-make-link', $detail);
        self::assertStringContainsString('data-renew', $detail, 'D5: he may still renew');

        // The owner screens and actions refuse him now.
        $this->appGet('/app/view/services/'.$this->sold->getId().'/subscription', $session);
        self::assertSame(404, $this->httpStatus());
        $this->appPostJson('/app/api/services/'.$this->sold->getId().'/send-link', $session);
        self::assertSame(404, $this->httpStatus());
        $this->appPostJson('/app/api/reseller/services/'.$this->sold->getId().'/claim-link', $session);
        self::assertSame(409, $this->httpStatus());

        // D5: renewing at wholesale still works; its answer leads to the limited view, not to the customer's links.
        [$renewed] = $this->buyThroughApp($session, '/app/api/reseller/services/'.$this->sold->getId().'/renew', []);
        self::assertSame(200, $this->httpStatus(), (string) json_encode($renewed));
        self::assertSame('/app/view/reseller/services/'.$this->sold->getId(), $renewed['redirect']);
        self::assertStringNotContainsString((string) $service['public_token'], (string) json_encode($renewed));
    }

    public function testAnotherResellersServiceIsNotFoundEverywhere(): void
    {
        $session = $this->authenticate(self::CUSTOMER);
        $id = (int) $this->othersService->getId();
        foreach (['', '/renew', '/add-traffic'] as $suffix) {
            $this->appGet('/app/view/reseller/services/'.$id.$suffix, $session);
            self::assertSame(404, $this->httpStatus(), $suffix);
        }
        foreach (['/customer', '/claim-link'] as $suffix) {
            $this->appPostJson('/app/api/reseller/services/'.$id.$suffix, $session, ['customerId' => ''], self::newKey());
            self::assertContains($this->httpStatus(), [404, 409], $suffix);
        }
        self::assertSame(0, $this->sqlInt('SELECT COUNT(*) FROM service_claim WHERE service_id = '.$id));
        self::assertSame(0, $this->sqlInt('SELECT COUNT(*) FROM reseller_customer_service WHERE service_id = '.$id));
        // An id that does not exist answers the same.
        $this->appGet('/app/view/reseller/services/999999999', $session);
        self::assertSame(404, $this->httpStatus());
    }

    private function claimByCustomer(): void
    {
        $this->claimWith($this->makeLink()->token, self::CLAIMER);
        $account = $this->em->getRepository(TelegramAccount::class)->findOneBy(['telegramId' => self::CLAIMER]) ?? self::fail('no claimer');
        $this->em->getConnection()->executeStatement('UPDATE telegram_account SET first_name = ?, username = ? WHERE id = ?', [self::CLAIMER_NAME, self::CLAIMER_USERNAME, $account->getId()]);
        $this->em->getConnection()->executeStatement('UPDATE `user` SET name = ? WHERE id = ?', [self::CLAIMER_NAME, $account->getUser()->getId()]);
    }
}
