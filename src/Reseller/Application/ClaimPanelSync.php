<?php

declare(strict_types=1);

namespace App\Reseller\Application;

use App\Entity\ServiceClaim;
use App\Entity\VpnService;
use App\Provisioning\Domain\ClientOwnerDriverInterface;
use App\Provisioning\Infrastructure\VpnPanelDriverRegistry;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

/**
 * Tells the panel who owns a client after its service changed owner (docs/design/reseller-and-claim.md, 3.4), best
 * effort and always after the ownership change was committed: on a modern 3x-ui panel tgId = the owner's Telegram id
 * and comment = "amoobot order #N, claimed" (or "amoobot order #N" when the reseller who sold it owns it again), through
 * ModernClientsApi::updateClient() (ClientOwnerDriverInterface). A panel whose driver cannot do it (legacy 3x-ui: no
 * tgId support) is skipped. The database stays the source of truth for ownership; this only keeps the panel's own
 * view in line.
 *
 * It always writes the service's CURRENT owner, read now, so a retry after another change is still right. The state is
 * on the claim row (ServiceClaim::panelSync*): a failure is retried by app:claims:expire after a growing delay
 * (5 min, 10, 20, ... up to 6 h), and given up (failed, shown on the admin claims page) after MAX_ATTEMPTS.
 */
class ClaimPanelSync
{
    public const MAX_ATTEMPTS = 12;
    private const FIRST_DELAY_SECONDS = 300;
    private const MAX_DELAY_SECONDS = 21600;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly VpnPanelDriverRegistry $drivers,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * One attempt for this claim (claimed, panel sync pending). Never throws: the outcome is saved on the claim.
     *
     * @return string ServiceClaim::SYNC_*
     */
    public function sync(ServiceClaim $claim): string
    {
        $service = $claim->getService();
        $this->entityManager->refresh($service);
        $now = $this->clock->now();
        $remoteId = trim((string) $service->getRemoteId());
        $panel = $service->getPanel();

        try {
            $driver = null === $panel || '' === $remoteId ? null : $this->drivers->resolve($panel);
            if (!$driver instanceof ClientOwnerDriverInterface) {
                $claim->panelSyncSkipped(null === $panel || '' === $remoteId ? 'no panel client' : 'the panel driver cannot set the owner (legacy)');
                $this->entityManager->flush();

                return ServiceClaim::SYNC_SKIPPED;
            }
            $driver->updateClientOwner($remoteId, $this->ownerTelegramId($service), $this->comment($service), $panel);
            $claim->panelSynced($now);
            $this->entityManager->flush();
            $this->logger->info('claim_panel_synced', ['claim_id' => $claim->getId(), 'service_id' => $service->getId()]);

            return ServiceClaim::SYNC_SYNCED;
        } catch (\Throwable $e) {
            $attempt = $claim->getPanelSyncAttempts() + 1;
            $nextAt = $attempt >= self::MAX_ATTEMPTS ? null : $now->modify(sprintf('+%d seconds', min(self::MAX_DELAY_SECONDS, self::FIRST_DELAY_SECONDS * (2 ** ($attempt - 1)))));
            $claim->panelSyncFailed($e->getMessage(), $nextAt);
            if ($this->entityManager->isOpen()) {
                $this->entityManager->flush();
            }
            $this->logger->warning('claim_panel_sync_failed', ['claim_id' => $claim->getId(), 'service_id' => $service->getId(), 'attempt' => $attempt, 'gave_up' => null === $nextAt, 'error' => $e->getMessage()]);

            return $claim->getPanelSyncStatus() ?? ServiceClaim::SYNC_FAILED;
        }
    }

    private function ownerTelegramId(VpnService $service): int
    {
        $telegramId = (string) $service->getUser()->getTelegramAccount()?->getTelegramId();

        return 1 === preg_match('/^[1-9][0-9]{0,18}$/D', $telegramId) ? (int) $telegramId : 0;
    }

    private function comment(VpnService $service): string
    {
        $base = null === $service->getOrder()?->getId() ? 'amoobot' : sprintf('amoobot order #%d', (int) $service->getOrder()->getId());
        $seller = $service->getSoldByReseller()?->getUser()->getId();

        return null !== $seller && $seller === $service->getUser()->getId() ? $base : $base.', claimed';
    }
}
