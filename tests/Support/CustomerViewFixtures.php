<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\Plan;
use App\Entity\Setting;
use App\Entity\User;
use App\Entity\VpnInbound;
use App\Entity\VpnService;
use App\Entity\VpnServiceInbound;
use App\MiniApp\Application\MiniAppLinks;
use App\Provisioning\Domain\VpnServiceStatus;
use App\Shop\Application\MultiInboundSettings;
use App\Shop\Domain\OrderStatus;

/**
 * VPN services in every state a customer can see, with fixed, public-looking links (no randomness), for the
 * customer-view golden files (CustomerViewSnapshot). Every date is an offset from $this->now, so the normaliser can
 * name it. For IntegrationTestCase subclasses.
 */
trait CustomerViewFixtures
{
    protected const VIEW_CUSTOMER = '7301';
    private const GIB = 1073741824;

    /** Every state the customer-view snapshots cover (reseller-sold ones are in the claim tests). */
    protected const STATES = ['active', 'near_expiry', 'expired', 'suspended', 'traffic_exhausted', 'unlimited', 'multi_inbound', 'imported'];

    protected \DateTimeImmutable $now;
    protected User $viewCustomer;
    protected VpnInbound $viewInbound;
    protected Plan $viewPlan;
    /** @var array<int, string> service id => placeholder */
    protected array $serviceNames = [];
    /** @var array<string, string> opaque value => placeholder */
    protected array $opaque = [];

    protected function setUpCustomerView(): void
    {
        $this->now = new \DateTimeImmutable('@'.time());
        $this->now = $this->now->setTimezone(new \DateTimeZone(date_default_timezone_get()));
        $connection = $this->em->getConnection();
        foreach (['mini_app_session', 'checkout_request', 'trial_claim', 'trial_plan', 'admin_audit_log', 'admin_user', 'required_channel'] as $table) {
            $connection->executeStatement('DELETE FROM '.$table);
        }
        $connection->executeStatement("DELETE FROM setting WHERE key_name LIKE 'sales.%' OR key_name LIKE 'traffic_addon.%' OR key_name LIKE 'payment.%' OR key_name = ?", [MiniAppLinks::ENABLED_SETTING]);
        // Not purged between tests: the Mini App button is off unless a test turns it on.
        $this->viewSetting(MiniAppLinks::ENABLED_SETTING, 'false');
        // Links the provisioning in the delivery tests creates: public addresses, so nothing is withheld.
        $this->driver()->subscriptionBase = 'https://sub.shop-edge.com:2096/sub';
        $this->driver()->linkHost = 'de1.shop-edge.com';

        $this->viewCustomer = $this->createUser(self::VIEW_CUSTOMER);
        $this->viewInbound = $this->viewInbound('Germany 1', 'de1.shop-edge.com', 'DE');
        $this->viewPlan = $this->createPlan(150000, $this->viewInbound)->setTitle('Gold 30 days');
        $this->em->flush();
    }

    protected function tearDownCustomerView(): void
    {
        if (isset($this->em) && $this->em->isOpen()) {
            $this->em->getConnection()->executeStatement('DELETE FROM setting WHERE key_name = ?', [MiniAppLinks::ENABLED_SETTING]);
        }
    }

    protected function viewInbound(string $title, string $host, string $country): VpnInbound
    {
        $inbound = $this->createTestInbound()->setTitle($title)->setHost($host)->setCountry($country)->setProtocol('vless')->setNetwork('tcp')->setSecurity('reality')->setPort(443);
        $this->em->flush();

        return $inbound;
    }

    /**
     * One service of the customer in a state of STATES, with an order of the plan (except "imported").
     */
    protected function stateService(string $state, ?User $owner = null): VpnService
    {
        $n = array_search($state, self::STATES, true);
        self::assertIsInt($n, 'unknown state '.$state);
        $uuid = sprintf('5e1c0a7e-0000-4000-8000-%012d', $n + 1);
        $subId = 'sub'.str_replace('_', '', $state);
        $link = 'vless://'.$uuid.'@de1.shop-edge.com:443?type=tcp&security=reality&sni=www.example.com#DE-'.$state;
        $service = (new VpnService())
            ->setUser($owner ?? $this->viewCustomer)
            ->setPanel($this->viewInbound->getPanel())
            ->setInbound($this->viewInbound)
            ->setRemoteId('test_'.$uuid)
            ->setUsername('cv_'.$state)
            ->setClientEmail('cv_'.$state)
            ->setClientUuid($uuid)
            ->setSubId($subId)
            ->setSubscriptionUrl('https://sub.shop-edge.com:2096/sub/'.$subId)
            ->setConfigLinks([$link])
            ->setConfigText($link)
            ->setStatus(VpnServiceStatus::ACTIVE)
            ->setStartsAt($this->now->modify('-10 days'))
            ->setExpiresAt($this->now->modify('+20 days 12 hours'))
            ->setTrafficLimitGb(50)
            ->setTrafficUsedGb(12)
            ->setTrafficLimitBytes(50 * self::GIB)
            ->setTrafficUsedBytes((int) (12.5 * self::GIB));

        match ($state) {
            'near_expiry' => $service->setExpiresAt($this->now->modify('+2 days 12 hours')),
            'expired' => $service->setStatus(VpnServiceStatus::EXPIRED)->setExpiresAt($this->now->modify('-2 days -12 hours')),
            'suspended' => $service->setStatus(VpnServiceStatus::SUSPENDED),
            'traffic_exhausted' => $service->setTrafficUsedGb(50)->setTrafficUsedBytes(50 * self::GIB),
            'unlimited' => $service->setTrafficLimitGb(null)->setTrafficLimitBytes(null)->setTrafficUsedGb(3)->setTrafficUsedBytes(3 * self::GIB),
            'imported' => $service->setImportedAt($this->now->modify('-30 days')),
            default => null,
        };
        $this->em->persist($service);
        $this->em->flush();

        if ('imported' !== $state) {
            $order = $this->createOrder($service->getUser(), $this->viewPlan);
            $this->em->getConnection()->executeStatement('UPDATE `order` SET status = ? WHERE id = ?', [OrderStatus::PROVISIONED, $order->getId()]);
            $this->em->refresh($order);
            $service->setOrder($order);
            $this->opaque[(string) $order->getTrackingCode()] = 'TRACKING_'.strtoupper($state);
        }
        if ('multi_inbound' === $state) {
            // A plan with several inbounds (switch on): one more server connected, one that failed for good.
            $this->viewSetting(MultiInboundSettings::ENABLED, 'true');
            $this->em->persist((new VpnServiceInbound($service, $this->viewInbound('Netherlands 1', 'nl1.shop-edge.com', 'NL'), $this->now))->recordAttempt(true, null, $this->now));
            $failed = new VpnServiceInbound($service, $this->viewInbound('Finland 1', 'fi1.shop-edge.com', 'FI'), $this->now);
            for ($i = 0; $i < MultiInboundSettings::DEFAULT_MAX_ATTEMPTS; ++$i) {
                $failed->recordAttempt(false, 'panel refused', $this->now);
            }
            $this->em->persist($failed);
        }
        $this->em->flush();
        $this->nameService($service, strtoupper($state));

        return $service;
    }

    /** Registers the service's id and its opaque values (token, credentials) under one name. */
    protected function nameService(VpnService $service, string $name): void
    {
        $this->serviceNames[(int) $service->getId()] = 'SERVICE_'.$name;
        $this->opaque[(string) $service->getPublicToken()] = 'TOKEN_'.$name;
    }

    /**
     * Every service's expiry as stored now (CustomerViewSnapshot names a calendar day after the instant it shows).
     *
     * @return list<\DateTimeImmutable>
     */
    protected function expiries(): array
    {
        $timezone = $this->now->getTimezone();

        return array_map(static fn (string $at): \DateTimeImmutable => new \DateTimeImmutable($at, $timezone), $this->em->getConnection()->fetchFirstColumn('SELECT expires_at FROM vpn_service WHERE expires_at IS NOT NULL'));
    }

    protected function viewSetting(string $key, string $value): void
    {
        $setting = $this->em->getRepository(Setting::class)->findOneBy(['keyName' => $key]) ?? (new Setting())->setKeyName($key)->setType('string');
        $setting->setValue($value);
        $this->em->persist($setting);
        $this->em->flush();
    }

    /**
     * BotTranscript ids for the services named so far.
     *
     * @return array<string, array<int, string>>
     */
    protected function transcriptIds(): array
    {
        return ['SERVICE' => $this->serviceNames];
    }

    /**
     * What the bot sent since the last reset, normalised (customer chat only unless $allChats).
     *
     * @return list<string>
     */
    protected function transcript(bool $allChats = false, array $extraIds = []): array
    {
        $requests = $this->http->requests();
        $ids = $this->transcriptIds();
        foreach ($extraIds as $table => $names) {
            $ids[$table] = ($ids[$table] ?? []) + $names;
        }
        $lines = BotTranscript::lines($requests, $this->opaque, $ids);
        if (!$allChats) {
            $lines = array_values(array_filter($lines, static fn (string $l): bool => !str_contains(strtok($l, "\n") ?: '', 'chat=999000')));
        }

        return $lines;
    }
}
