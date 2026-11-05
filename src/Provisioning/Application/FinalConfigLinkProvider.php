<?php

declare(strict_types=1);

namespace App\Provisioning\Application;

use App\Entity\VpnService;
use App\Shared\Infrastructure\SafeErrorLog;

final class FinalConfigLinkProvider
{
    public function __construct(
        private readonly VpnAccessLinkGenerator $vpnAccessLinkGenerator,
        private readonly VpnConfigLinkSet $vpnConfigLinkSet,
    ) {
    }

    /**
     * @return list<string>
     */
    public function getFinalLinksForService(VpnService $service, string $sourceFlow = 'unknown'): array
    {
        return $this->getFinalLinkSetForService($service, $sourceFlow)->finalLinks;
    }

    /**
     * The single config links a customer may be shown. While linksPending() holds, none: the stored snapshot may still
     * name the inbound the service left (docs/design/plan-inbound-change.md 5.5), so the result is empty with
     * linksPending set, and the callers show the subscription URL (live, served by the panel) with LinksUpdatingNotice.
     * Read from the stored row only: no panel call (rule 9.1).
     */
    public function getFinalLinkSetForService(VpnService $service, string $sourceFlow = 'unknown'): VpnConfigLinkSetResult
    {
        if ($this->linksPending($service)) {
            SafeErrorLog::write(sprintf(
                '[FinalConfigLinkProvider] links_pending service_id=%d source_flow="%s" final_count=0',
                (int) ($service->getId() ?? 0),
                $sourceFlow
            ));

            return new VpnConfigLinkSetResult(0, 0, [], 0, true);
        }

        $rawLinks = $this->withFallbackFragments($this->storedLinks($service), (string) ($service->getUsername() ?? ''));
        $formattedLinks = [];

        if (!$this->shouldUseStoredLinksOnly($service, $rawLinks)) {
            try {
                $generated = $this->vpnAccessLinkGenerator->generate($service);
                $formattedLinks = $this->withFallbackFragments(array_values(array_filter(
                    (array) ($generated['configLinks'] ?? []),
                    static fn (mixed $link): bool => '' !== trim((string) $link)
                )), (string) ($service->getUsername() ?? ''));
            } catch (\Throwable $e) {
                SafeErrorLog::write(sprintf(
                    '[FinalConfigLinkProvider] generate_failed service_id=%d source_flow="%s" message="%s"',
                    (int) ($service->getId() ?? 0),
                    $sourceFlow,
                    ServiceErrorText::redact($service, $e->getMessage())
                ));
            }
        }

        $result = $this->deduplicateAndPreferFormattedWithStats($rawLinks, $formattedLinks);
        $this->logResult($service, $sourceFlow, count($rawLinks), count($formattedLinks), $result);

        return $result;
    }

    /**
     * The display guard of docs/design/plan-inbound-change.md 5.5: a modern 3x-ui service whose primary inbound was
     * changed by an inbound move (vpn_service.inbound_changed_at) after its links were last read from the panel
     * (last_access_info_synced_at, written by every successful PanelLinkSnapshot store). Its stored single links may
     * still be the old inbound's, so they are withheld until the next successful refresh, which clears this by itself.
     * Compared in whole seconds, as the columns store them (the runner's own test, InboundMoveRunner::LINKS_PENDING_SQL).
     * Never for a service without inbound_changed_at (every service until a move), a legacy panel (its links are rebuilt
     * from the panel before each bot delivery) or another panel type.
     */
    public function linksPending(VpnService $service): bool
    {
        $changedAt = $service->getInboundChangedAt();
        if (null === $changedAt) {
            return false;
        }
        $panel = $service->getPanel();
        if (null === $panel || 'sanaei_3xui' !== strtolower(trim((string) $panel->getType())) || !$panel->isModern()) {
            return false;
        }
        $syncedAt = $service->getLastAccessInfoSyncedAt();

        return null === $syncedAt || $changedAt->getTimestamp() > $syncedAt->getTimestamp();
    }

    /**
     * @param list<string> $rawLinks
     * @param list<string> $formattedLinks
     *
     * @return list<string>
     */
    public function deduplicateAndPreferFormatted(array $rawLinks, array $formattedLinks): array
    {
        return $this->deduplicateAndPreferFormattedWithStats($rawLinks, $formattedLinks)->finalLinks;
    }

    /**
     * @param list<string> $rawLinks
     * @param list<string> $formattedLinks
     */
    public function deduplicateAndPreferFormattedWithStats(array $rawLinks, array $formattedLinks): VpnConfigLinkSetResult
    {
        $preferredInput = [] !== $formattedLinks ? array_merge($rawLinks, $formattedLinks) : $rawLinks;

        return $this->vpnConfigLinkSet->filter($preferredInput, count($rawLinks), count($formattedLinks));
    }

    /**
     * @param list<string> $rawLinks
     * @param list<string> $formattedLinks
     */
    public function deduplicateAndPreferFormattedForService(VpnService $service, array $rawLinks, array $formattedLinks, string $sourceFlow): VpnConfigLinkSetResult
    {
        $result = $this->deduplicateAndPreferFormattedWithStats($rawLinks, $formattedLinks);
        $this->logResult($service, $sourceFlow, count($rawLinks), count($formattedLinks), $result);

        return $result;
    }

    /**
     * @return list<string>
     */
    private function storedLinks(VpnService $service): array
    {
        return array_values(array_filter(
            array_map(static fn (mixed $link): string => trim((string) $link), (array) ($service->getConfigLinks() ?? [])),
            static fn (string $link): bool => '' !== $link
        ));
    }

    /**
     * @param list<string> $links
     *
     * @return list<string>
     */
    private function withFallbackFragments(array $links, string $fallbackName): array
    {
        $fallbackName = trim($fallbackName);
        if ('' === $fallbackName) {
            return $links;
        }

        return array_values(array_map(static function (string $link) use ($fallbackName): string {
            $fragment = parse_url($link, PHP_URL_FRAGMENT);
            if (is_string($fragment) && '' !== trim($fragment)) {
                return $link;
            }

            $withoutFragment = preg_replace('/#.*$/', '', $link) ?? $link;

            return $withoutFragment.'#'.rawurlencode($fallbackName);
        }, $links));
    }

    /**
     * @param list<string> $storedLinks
     */
    private function shouldUseStoredLinksOnly(VpnService $service, array $storedLinks): bool
    {
        if ([] === $storedLinks) {
            return false;
        }

        return 'sanaei_3xui' === strtolower(trim((string) ($service->getPanel()?->getType() ?? '')));
    }

    private function logResult(VpnService $service, string $sourceFlow, int $rawCount, int $formattedCount, VpnConfigLinkSetResult $result): void
    {
        SafeErrorLog::write(sprintf(
            '[FinalConfigLinkProvider] service_id=%d source_flow="%s" raw_link_count=%d formatted_link_count=%d final_count=%d dropped_duplicate_count=%d',
            (int) ($service->getId() ?? 0),
            $sourceFlow,
            $rawCount,
            $formattedCount,
            count($result->finalLinks),
            $result->droppedDuplicateCount
        ));
    }
}
