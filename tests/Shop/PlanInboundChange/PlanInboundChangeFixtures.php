<?php

declare(strict_types=1);

namespace App\Tests\Shop\PlanInboundChange;

use App\Entity\Plan;
use App\Entity\User;
use App\Entity\VpnInbound;
use App\Entity\VpnPanel;
use App\Entity\VpnService;
use App\Provisioning\Domain\SanaeiIntegrationMode;
use App\Provisioning\Domain\VpnServiceStatus;
use App\Provisioning\Infrastructure\Sanaei3xui\Sanaei3xuiRemoteIdParser;
use App\Shop\Domain\OrderStatus;
use App\Shop\Domain\OrderType;
use App\Tests\Support\Fake3xui\Fake3xuiPanel;

/**
 * A modern 3x-ui panel (the fake) with inbounds DE-1 (remote 1) and NL-2 (remote 2), plan "Gold" selling DE-1, and
 * services of that plan: what an owner has before changing the plan's inbound (docs/design/plan-inbound-change.md 2).
 *
 * @mixin \App\Tests\Support\IntegrationTestCase
 */
trait PlanInboundChangeFixtures
{
    private Fake3xuiPanel $fake;
    private VpnPanel $modernPanel;
    private VpnInbound $de;
    private VpnInbound $nl;
    private Plan $gold;
    private User $owner;

    private function setUpInboundChange(): void
    {
        $this->fake = new Fake3xuiPanel();
        $this->http->on('panel.example.test', $this->fake);
        $this->modernPanel = (new VpnPanel())->setType('sanaei_3xui')->setTitle('modern')->setBaseUrl(Fake3xuiPanel::ORIGIN.'/secret/')->setPlainApiToken(Fake3xuiPanel::TOKEN)
            ->recordProbe(SanaeiIntegrationMode::MODERN, 'ok', new \DateTimeImmutable());
        $this->em->persist($this->modernPanel);
        $this->de = $this->panelInbound(1, 'DE-1');
        $this->nl = $this->panelInbound(2, 'NL-2');
        $this->em->flush();
        $this->fake->addHostGroup('cdn', [1, 2], ['cdn1.example.com']);
        $this->gold = $this->createPlan(100000, $this->de)->setTitle('Gold');
        $this->owner = $this->createUser('8940001')->setName('Owner Kaveh');
        $this->em->flush();
    }

    private function panelInbound(int $remote, string $title): VpnInbound
    {
        $inbound = (new VpnInbound())->setPanel($this->modernPanel)->setRemoteInboundId((string) $remote)->setTitle($title)->setProtocol('vless')
            ->setHost('origin.example.com')->setPort(443)->setNetwork('tcp')->setSecurity('none');
        $this->em->persist($inbound);

        return $inbound;
    }

    /** A service of $plan on $inbound; $n > 0 gives it a client on the fake panel (on that inbound) for regenerate. */
    private function planService(Plan $plan, VpnInbound $inbound, string $status = VpnServiceStatus::ACTIVE, int $n = 0, string $type = OrderType::NEW_SERVICE): VpnService
    {
        [$plan, $inbound, $this->owner] = [$this->managed($plan), $this->managed($inbound), $this->managed($this->owner)];
        $this->modernPanel = $this->managed($this->modernPanel);
        $order = $this->createOrder($this->owner, $plan, $type)->setStatus(OrderStatus::COMPLETED);
        $service = $this->createActiveService($this->owner, $inbound)->setOrder($order)->setStatus($status);
        if ($n > 0) {
            $uuid = sprintf('%08d-2222-3333-4444-555555555555', $n);
            $email = 'tg_p1_'.$n;
            $subId = 'p1sub'.str_repeat((string) $n, 10);
            $this->fake->clients[$email] = [
                'email' => $email, 'id' => $uuid, 'subId' => $subId, 'flow' => '', 'totalGB' => 0, 'expiryTime' => 0, 'limitIp' => 0,
                'limitHwid' => 0, 'tgId' => 0, 'comment' => '', 'enable' => true, 'group' => '', 'reset' => 0, 'inboundIds' => [(int) $inbound->getRemoteInboundId()], 'dbId' => $n,
            ];
            $service->setRemoteId((new Sanaei3xuiRemoteIdParser())->format($this->modernPanel->getId(), $inbound->getId(), (string) $inbound->getRemoteInboundId(), $uuid, $email, $subId))
                ->setClientUuid($uuid)->setClientEmail($email)->setSubId($subId)
                ->setConfigLinks(['vless://'.$uuid.'@origin.example.com:443?type=tcp#old'])->setConfigText('vless://'.$uuid.'@origin.example.com:443?type=tcp#old');
        }
        $this->em->flush();

        return $service;
    }

    /** The plan now sells $inbound (as a form would have saved it). */
    private function sellOn(Plan $plan, VpnInbound $inbound): void
    {
        $this->em->getConnection()->update('plan', ['inbound_id' => $inbound->getId()], ['id' => $plan->getId()]);
        $this->em->refresh($this->managed($plan));
    }

    /**
     * The managed copy of a fixture: a request through the kernel resets the entity manager, which detaches them.
     *
     * @template T of object
     *
     * @param T $entity
     *
     * @return T
     */
    private function managed(object $entity): object
    {
        if ($this->em->contains($entity)) {
            return $entity;
        }
        $id = $this->em->getClassMetadata($entity::class)->getIdentifierValues($entity);

        return $this->em->find($entity::class, $id) ?? throw new \LogicException('gone: '.$entity::class);
    }

    /**
     * Every row of the tables a notice could write to, to show nothing was written.
     *
     * @return array<string, string>
     */
    private function checksums(): array
    {
        $sums = [];
        foreach (['plan', 'order', 'vpn_service', 'vpn_service_inbound', 'plan_additional_inbound', 'trial_plan', 'vpn_inbound', 'setting'] as $table) {
            $sums[$table] = (string) $this->em->getConnection()->fetchAssociative('CHECKSUM TABLE `'.$table.'`')['Checksum'];
        }

        return $sums;
    }

    /**
     * @param array<string, string|int> $parameters
     */
    private function notice(string $key, array $parameters, string $locale = 'en'): string
    {
        return self::getContainer()->get('translator')->trans($key, $parameters, 'admin', $locale);
    }
}
