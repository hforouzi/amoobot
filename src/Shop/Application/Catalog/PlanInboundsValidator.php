<?php

declare(strict_types=1);

namespace App\Shop\Application\Catalog;

use App\Entity\Plan;
use App\Entity\VpnInbound;
use App\Provisioning\Infrastructure\Sanaei3xui\Modern\Http\PanelApiException;
use App\Provisioning\Infrastructure\Sanaei3xui\Modern\ModernClientsApi;
use App\Shop\Application\MultiInboundSettings;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

/**
 * The rules for a plan's additional inbounds (docs/design/multi-inbound-plans.md 2.1, 3.1, D1), checked on every save:
 *
 * - while plans.multi_inbound_enabled is off they cannot be changed at all (the web form has no field; anything else
 *   that tries is refused here);
 * - a primary inbound is required; the plan is not customizable (custom traffic/duration plans stay single-inbound);
 *   at most MAX_ADDITIONAL;
 * - every additional inbound is on the primary's panel and is not the primary; a newly chosen one is active (a kept one
 *   that became inactive since is a warning, PlanInboundWarnings);
 * - the panel is a 3x-ui panel in modern mode (the effective mode: forced, or auto with the detected one) when the
 *   inbounds or the primary change;
 * - D1: only VLESS, VMess, Trojan and Shadowsocks; Shadowsocks only when every SS inbound of the plan has the same
 *   method; a VLESS primary with a vision flow only with flow-capable VLESS additional inbounds. These two read the
 *   panel's inbounds/options (the methods and flow capability are not stored here), only when they matter and the
 *   inbounds or primary changed; a panel that cannot be read refuses the save.
 */
final class PlanInboundsValidator extends ConstraintValidator
{
    public const MAX_ADDITIONAL = 20;
    public const PROTOCOLS = ['vless', 'vmess', 'trojan', 'shadowsocks'];
    /** The web form's field (Plan::getAdditionalInboundList()), so errors show next to it. */
    public const PATH = 'additionalInboundList';

    public function __construct(
        private readonly MultiInboundSettings $settings,
        private readonly ModernClientsApi $api,
    ) {
    }

    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$value instanceof Plan) {
            return;
        }
        if ($value->additionalInboundsChanged() && !$this->settings->enabled()) {
            $this->violation('plan.additional_inbounds.feature_off');

            return;
        }
        $additional = $value->getAdditionalInbounds();
        if ([] === $additional) {
            return;
        }
        $primary = $value->getInbound();
        if (null === $primary) {
            $this->violation('plan.additional_inbounds.no_primary');

            return;
        }
        $changed = $value->additionalInboundsChanged() || $value->primaryInboundChanged();
        $before = count($this->context->getViolations());

        if ($value->isCustomizable()) {
            $this->violation('plan.additional_inbounds.customizable');
        }
        if (count($additional) > self::MAX_ADDITIONAL) {
            $this->violation('plan.additional_inbounds.too_many', ['%max%' => self::MAX_ADDITIONAL]);
        }
        $added = array_map('spl_object_id', $value->addedAdditionalInbounds());
        foreach ($additional as $inbound) {
            if ($inbound === $primary || (null !== $inbound->getId() && $inbound->getId() === $primary->getId())) {
                $this->violation('plan.additional_inbounds.primary_repeated', ['%inbound%' => self::name($inbound)]);
            } elseif ($inbound->getPanel() !== $primary->getPanel()) {
                $this->violation('plan.additional_inbounds.other_panel', ['%inbound%' => self::name($inbound)]);
            } elseif (!$inbound->isActive() && in_array(spl_object_id($inbound), $added, true)) {
                $this->violation('plan.additional_inbounds.inactive', ['%inbound%' => self::name($inbound)]);
            }
        }
        $panel = $primary->getPanel();
        if ($changed && ('sanaei_3xui' !== $panel->getType() || !$panel->isModern())) {
            $this->violation('plan.additional_inbounds.not_modern');
        }
        foreach ($value->getInbounds() as $inbound) {
            if (!in_array(self::protocol($inbound), self::PROTOCOLS, true)) {
                $this->violation('plan.additional_inbounds.protocol', ['%inbound%' => self::name($inbound), '%protocol%' => '' === self::protocol($inbound) ? '?' : self::protocol($inbound)]);
            }
        }
        if (!$changed || count($this->context->getViolations()) > $before) {
            return;
        }
        $this->checkAgainstThePanel($primary, $additional);
    }

    /**
     * D1's two rules that need the panel: the Shadowsocks methods and the VLESS flow capability.
     *
     * @param list<VpnInbound> $additional
     */
    private function checkAgainstThePanel(VpnInbound $primary, array $additional): void
    {
        $inbounds = [$primary, ...$additional];
        $shadowsocks = array_values(array_filter($inbounds, static fn (VpnInbound $i): bool => 'shadowsocks' === self::protocol($i)));
        $vision = 'vless' === self::protocol($primary) && str_contains(strtolower((string) $primary->getFlow()), 'vision');
        $vless = array_values(array_filter($additional, static fn (VpnInbound $i): bool => 'vless' === self::protocol($i)));
        if (count($shadowsocks) < 2 && (!$vision || [] === $vless)) {
            return;
        }
        try {
            $options = [];
            foreach ($this->api->inboundOptions($primary->getPanel()) as $option) {
                $options[(string) $option['id']] = $option;
            }
        } catch (PanelApiException) {
            $this->violation('plan.additional_inbounds.panel_unreadable');

            return;
        }
        if (count($shadowsocks) >= 2) {
            $methods = array_map(static fn (VpnInbound $i): string => strtolower(trim((string) ($options[$i->getRemoteInboundId()]['ssMethod'] ?? ''))), $shadowsocks);
            if (in_array('', $methods, true) || count(array_unique($methods)) > 1) {
                $this->violation('plan.additional_inbounds.ss_methods');
            }
        }
        if ($vision) {
            foreach ($vless as $inbound) {
                if (true !== ($options[$inbound->getRemoteInboundId()]['tlsFlowCapable'] ?? null)) {
                    $this->violation('plan.additional_inbounds.vision', ['%inbound%' => self::name($inbound)]);
                }
            }
        }
    }

    /**
     * @param array<string, string|int> $parameters
     */
    private function violation(string $message, array $parameters = []): void
    {
        $this->context->buildViolation($message, $parameters)->atPath(self::PATH)->addViolation();
    }

    public static function protocol(VpnInbound $inbound): string
    {
        return strtolower(trim((string) $inbound->getProtocol()));
    }

    private static function name(VpnInbound $inbound): string
    {
        return sprintf('#%d %s', (int) $inbound->getId(), mb_substr($inbound->getTitle(), 0, 60));
    }
}
