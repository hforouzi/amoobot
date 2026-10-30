<?php

declare(strict_types=1);

namespace App\Tests\Shop\MultiInbound;

use App\Entity\Plan;
use App\Entity\VpnInbound;
use App\Entity\VpnPanel;
use App\Provisioning\Domain\SanaeiIntegrationMode;
use App\Shop\Application\Catalog\InvalidCatalogEntry;
use App\Shop\Application\Catalog\PlanAdminService;
use App\Shop\Application\Catalog\PlanInboundWarnings;
use App\Shop\Application\MultiInboundSettings;
use App\Tests\Support\Fake3xui\Fake3xuiPanel;
use App\Tests\Support\IntegrationTestCase;

/**
 * The save rules for a plan's additional inbounds (docs/design/multi-inbound-plans.md 2.1, 3.1, D1), as every channel
 * applies them (PlanAdminService::save()). The fake panel knows each inbound's Shadowsocks method and VLESS flow
 * capability (inbounds/options), which are not stored locally.
 */
final class PlanInboundRulesTest extends IntegrationTestCase
{
    private Fake3xuiPanel $fake;
    private VpnPanel $panel;
    /** @var array<string, VpnInbound> */
    private array $in = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->fake = new Fake3xuiPanel();
        $this->http->on('panel.example.test', $this->fake);
        $this->panel = $this->modernPanel();
        // Fake panel inbounds 1 (VLESS REALITY, flow-capable), 2 (VMess WS), 3 (disabled Trojan) exist already.
        $this->fake->addInbound(['remark' => 'trojan', 'protocol' => 'trojan', 'port' => 2083]);                                    // 4
        $this->fake->addInbound(['remark' => 'vless-ws', 'protocol' => 'vless', 'port' => 8443]);                                   // 5
        $this->fake->addInbound(['remark' => 'ss-a', 'protocol' => 'shadowsocks', 'port' => 9001, 'ssMethod' => '2022-blake3-aes-128-gcm']); // 6
        $this->fake->addInbound(['remark' => 'ss-b', 'protocol' => 'shadowsocks', 'port' => 9002, 'ssMethod' => '2022-blake3-aes-128-gcm']); // 7
        $this->fake->addInbound(['remark' => 'ss-c', 'protocol' => 'shadowsocks', 'port' => 9003, 'ssMethod' => '2022-blake3-aes-256-gcm']); // 8
        $this->fake->addInbound(['remark' => 'vless-tcp', 'protocol' => 'vless', 'port' => 8444, 'tlsFlowCapable' => true]);         // 9
        $this->fake->addInbound(['remark' => 'hy2', 'protocol' => 'hysteria2', 'port' => 9443]);                                    // 10
        foreach ([
            'reality' => [1, 'vless', 'xtls-rprx-vision'], 'vmess' => [2, 'vmess', null], 'trojan' => [4, 'trojan', null], 'vlessWs' => [5, 'vless', null],
            'ssA' => [6, 'shadowsocks', null], 'ssB' => [7, 'shadowsocks', null], 'ssC' => [8, 'shadowsocks', null], 'vlessTcp' => [9, 'vless', null], 'hy2' => [10, 'hysteria2', null],
        ] as $key => [$remote, $protocol, $flow]) {
            $this->in[$key] = $this->inbound($this->panel, $remote, $key, $protocol, $flow);
        }
        $this->em->flush();
        $this->switchOn(true);
        self::getContainer()->get('translator')->setLocale('en');
    }

    public function testVlessVmessAndTrojanAreSavedInOrder(): void
    {
        $plan = $this->plan($this->in['reality']);
        $plan->setAdditionalInbounds([$this->in['trojan'], $this->in['vmess']]);

        $this->plans()->save($plan);

        self::assertSame([[(int) $this->in['trojan']->getId(), 1], [(int) $this->in['vmess']->getId(), 2]], array_map(
            static fn (array $r): array => [(int) $r['inbound_id'], (int) $r['sort_order']],
            $this->em->getConnection()->fetchAllAssociative('SELECT inbound_id, sort_order FROM plan_additional_inbound ORDER BY sort_order'),
        ));
        self::assertSame([], $this->fake->requestLines(), 'no panel call when D1 needs no panel data');
    }

    public function testWithTheSwitchOffTheyCannotBeChanged(): void
    {
        $this->switchOn(false);
        $plan = $this->plan($this->in['reality']);
        $plan->setAdditionalInbounds([$this->in['vmess']]);

        self::assertSame(['additionalInboundList' => ['Additional inbounds are switched off (Settings, Plans tab): they cannot be changed.']], $this->violations($plan));

        // A plan without additional inbounds saves exactly as before.
        $single = $this->plan($this->in['vmess']);
        $this->plans()->save($single->setTitle('still fine'));
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM plan_additional_inbound'));
    }

    /**
     * @return iterable<string, array{string, list<string>, string}>
     */
    public static function refusals(): iterable
    {
        yield 'the primary repeated' => ['reality', ['reality'], 'is the plan\'s inbound already'];
        yield 'a VLESS without flow next to a vision primary' => ['reality', ['vlessWs'], 'cannot carry the VLESS vision flow'];
        yield 'Shadowsocks with two methods' => ['vmess', ['ssA', 'ssC'], 'same method'];
        yield 'an unsupported protocol' => ['vmess', ['hy2'], 'uses hysteria2'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('refusals')]
    public function testRefusals(string $primary, array $additional, string $message): void
    {
        $plan = $this->plan($this->in[$primary]);
        $plan->setAdditionalInbounds(array_map(fn (string $k): VpnInbound => $this->in[$k], $additional));

        self::assertStringContainsString($message, implode(' ', $this->violations($plan)['additionalInboundList'] ?? []));
    }

    public function testAllowedD1Combinations(): void
    {
        foreach ([['vmess', ['ssA', 'ssB']], ['reality', ['vlessTcp', 'vmess', 'trojan']], ['vmess', ['vlessWs']]] as [$primary, $additional]) {
            $plan = $this->plan($this->in[$primary]);
            $plan->setAdditionalInbounds(array_map(fn (string $k): VpnInbound => $this->in[$k], $additional));
            self::assertSame([], $this->violations($plan), $primary.' + '.implode(', ', $additional));
        }
    }

    public function testAnotherPanelAnInactiveInboundAndALegacyPanel(): void
    {
        $other = $this->inbound($this->modernPanel(), 1, 'elsewhere', 'vmess', null);
        $inactive = $this->inbound($this->panel, 50, 'off', 'vmess', null)->setIsActive(false);
        $this->em->flush();

        $plan = $this->plan($this->in['vmess']);
        $plan->setAdditionalInbounds([$other, $inactive]);
        $messages = implode(' ', $this->violations($plan)['additionalInboundList']);
        self::assertStringContainsString('is on another panel', $messages);
        self::assertStringContainsString('is not active', $messages);

        $this->panel->setIntegrationMode(SanaeiIntegrationMode::LEGACY);
        $plan = $this->plan($this->in['vmess']);
        $plan->setAdditionalInbounds([$this->in['trojan']]);
        self::assertStringContainsString('need the panel in modern mode', implode(' ', $this->violations($plan)['additionalInboundList']));

        // auto + detected modern is modern; auto never probed is not.
        $this->panel->setIntegrationMode(SanaeiIntegrationMode::AUTO)->recordProbe(SanaeiIntegrationMode::MODERN, 'ok', new \DateTimeImmutable());
        self::assertSame([], $this->violations($plan));
        $fresh = (new VpnPanel())->setType('sanaei_3xui')->setTitle('auto')->setBaseUrl(Fake3xuiPanel::ORIGIN.'/secret/')->setPlainApiToken(Fake3xuiPanel::TOKEN);
        $this->em->persist($fresh);
        $a = $this->inbound($fresh, 2, 'a', 'vmess', null);
        $b = $this->inbound($fresh, 4, 'b', 'trojan', null);
        $this->em->flush();
        $plan = $this->plan($a);
        $plan->setAdditionalInbounds([$b]);
        self::assertStringContainsString('need the panel in modern mode', implode(' ', $this->violations($plan)['additionalInboundList']));
    }

    public function testCustomizableTooManyAndNoPrimary(): void
    {
        $plan = $this->plan($this->in['vmess'])->setIsCustomizable(true);
        $plan->setAdditionalInbounds([$this->in['trojan']]);
        self::assertStringContainsString('keeps one inbound', implode(' ', $this->violations($plan)['additionalInboundList']));

        $many = [];
        for ($n = 0; $n < 21; ++$n) {
            $many[] = $this->inbound($this->panel, 100 + $n, 'many '.$n, 'trojan', null);
        }
        $this->em->flush();
        $plan = $this->plan($this->in['vmess']);
        $plan->setAdditionalInbounds($many);
        self::assertStringContainsString('At most 20', implode(' ', $this->violations($plan)['additionalInboundList']));

        $plan = $this->plan(null);
        $plan->setAdditionalInbounds([$this->in['trojan']]);
        self::assertStringContainsString('Choose the plan\'s inbound first', implode(' ', $this->violations($plan)['additionalInboundList']));
    }

    public function testAPanelThatCannotBeAskedRefusesTheChecksThatNeedIt(): void
    {
        $this->fake->script('GET', 'panel/api/inbounds/options', Fake3xuiPanel::envelope(false, null, 'unauthorized', 401), 5);
        $plan = $this->plan($this->in['vmess']);
        $plan->setAdditionalInbounds([$this->in['ssA'], $this->in['ssB']]);

        self::assertStringContainsString('could not be asked', implode(' ', $this->violations($plan)['additionalInboundList']));
    }

    public function testAKeptInboundThatBecameInactiveIsAWarningNotARefusal(): void
    {
        $plan = $this->plan($this->in['reality']);
        $plan->setAdditionalInbounds([$this->in['vmess'], $this->in['trojan']]);
        $this->plans()->save($plan);
        $this->em->getConnection()->update('vpn_inbound', ['is_active' => 0], ['id' => $this->in['trojan']->getId()]);
        $this->em->clear();

        $plan = $this->em->find(Plan::class, $plan->getId()) ?? self::fail('plan gone');
        $this->plans()->save($plan->setTitle('renamed'));

        self::assertSame(['plan_inbounds.warning.not_sold', 'plan_inbounds.warning.inactive', 'plan_inbounds.warning.mixed'], array_column(PlanInboundWarnings::of($plan), 'key'));
        self::assertFalse($plan->isAvailableForSale());
    }

    public function testChangingThePrimaryToAnotherPanelIsRefusedWhileAdditionalInboundsStay(): void
    {
        $plan = $this->plan($this->in['vmess']);
        $plan->setAdditionalInbounds([$this->in['trojan']]);
        $this->plans()->save($plan);
        $elsewhere = $this->inbound($this->modernPanel(), 2, 'elsewhere', 'vmess', null);
        $this->em->flush();

        // A later request, as the Mini App or the bot would: only the primary changes, the switch may even be off.
        $this->switchOn(false);
        $this->em->clear();
        $plan = $this->em->find(Plan::class, $plan->getId()) ?? self::fail('plan gone');
        $plan->setInbound($this->em->find(VpnInbound::class, $elsewhere->getId()));
        self::assertStringContainsString('is on another panel', implode(' ', $this->violations($plan)['additionalInboundList']));
    }

    /**
     * @return array<string, list<string>>
     */
    private function violations(Plan $plan): array
    {
        try {
            $this->plans()->save($plan);
        } catch (InvalidCatalogEntry $e) {
            return $e->violations;
        }

        return [];
    }

    private function plans(): PlanAdminService
    {
        return self::getContainer()->get(PlanAdminService::class);
    }

    private function plan(?VpnInbound $primary): Plan
    {
        return (new Plan())->setTitle('Multi')->setDurationDays(30)->setTrafficGb(50)->setPrice(100000)->setInbound($primary);
    }

    private function modernPanel(): VpnPanel
    {
        $panel = (new VpnPanel())->setType('sanaei_3xui')->setTitle('modern')->setBaseUrl(Fake3xuiPanel::ORIGIN.'/secret/')->setPlainApiToken(Fake3xuiPanel::TOKEN)
            ->setIntegrationMode(SanaeiIntegrationMode::MODERN);
        $this->em->persist($panel);

        return $panel;
    }

    private function inbound(VpnPanel $panel, int $remote, string $title, string $protocol, ?string $flow): VpnInbound
    {
        $inbound = (new VpnInbound())->setPanel($panel)->setRemoteInboundId((string) $remote)->setTitle($title)->setProtocol($protocol)->setFlow($flow);
        $this->em->persist($inbound);

        return $inbound;
    }

    private function switchOn(bool $on): void
    {
        $this->em->getConnection()->executeStatement('DELETE FROM setting WHERE key_name = ?', [MultiInboundSettings::ENABLED]);
        $this->em->getConnection()->insert('setting', ['key_name' => MultiInboundSettings::ENABLED, 'value' => $on ? 'true' : 'false', 'type' => 'boolean', 'created_at' => '2026-01-01 00:00:00']);
    }
}
