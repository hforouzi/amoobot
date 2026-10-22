<?php

declare(strict_types=1);

namespace App\MiniApp\UI\Admin;

use App\Entity\VpnInbound;
use Symfony\Component\HttpFoundation\Request;

/**
 * Helpers of the Mini App admin forms: the JSON body app.js sends, and the inbound picker.
 */
final class AdminForms
{
    /**
     * @return array<string, mixed>
     */
    public static function body(Request $request): array
    {
        $data = 'json' === $request->getContentTypeFormat() ? json_decode($request->getContent(), true) : $request->request->all();

        return is_array($data) ? $data : [];
    }

    /**
     * The inbounds a plan can be bound to: every inbound, as the web form offers them, grouped by panel title; the
     * ones not for sale now (inactive, or on an unavailable panel) are marked. Only ids and titles, never a panel
     * address, credential or remote id.
     *
     * @param list<VpnInbound> $inbounds
     *
     * @return list<array{panel: string, options: list<array{id: int, title: string, available: bool}>}>
     */
    public static function inboundChoices(array $inbounds): array
    {
        $groups = [];
        foreach ($inbounds as $inbound) {
            $panel = $inbound->getPanel();
            $groups[$panel->getTitle()][] = [
                'id' => (int) $inbound->getId(),
                'title' => $inbound->getTitle(),
                'available' => $inbound->isActive() && $panel->isAvailableForSale(),
            ];
        }

        $choices = [];
        foreach ($groups as $panel => $options) {
            $choices[] = ['panel' => (string) $panel, 'options' => $options];
        }

        return $choices;
    }
}
