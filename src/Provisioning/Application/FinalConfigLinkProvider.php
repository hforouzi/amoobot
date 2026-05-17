<?php

declare(strict_types=1);

namespace App\Provisioning\Application;

use App\Entity\VpnService;

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

    public function getFinalLinkSetForService(VpnService $service, string $sourceFlow = 'unknown'): VpnConfigLinkSetResult
    {
        $rawLinks = $this->storedLinks($service);
        $formattedLinks = [];

        try {
            $generated = $this->vpnAccessLinkGenerator->generate($service);
            $formattedLinks = array_values(array_filter(
                (array) ($generated['configLinks'] ?? []),
                static fn (mixed $link): bool => '' !== trim((string) $link)
            ));
        } catch (\Throwable $e) {
            error_log(sprintf(
                '[FinalConfigLinkProvider] generate_failed service_id=%d source_flow="%s" message="%s"',
                (int) ($service->getId() ?? 0),
                $sourceFlow,
                $e->getMessage()
            ));
        }

        $result = $this->deduplicateAndPreferFormattedWithStats($rawLinks, $formattedLinks);
        $this->logResult($service, $sourceFlow, count($rawLinks), count($formattedLinks), $result);

        return $result;
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

    private function logResult(VpnService $service, string $sourceFlow, int $rawCount, int $formattedCount, VpnConfigLinkSetResult $result): void
    {
        error_log(sprintf(
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
