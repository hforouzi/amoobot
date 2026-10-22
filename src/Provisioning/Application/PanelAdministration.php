<?php

declare(strict_types=1);

namespace App\Provisioning\Application;

use App\Entity\VpnPanel;
use Doctrine\ORM\EntityManagerInterface;

/**
 * An admin's panel actions (PANELS_MANAGE) that need no credentials, for the web panel setup, the bot and the Mini
 * App: the connection test (PanelConnectionTester; the probe result is stored on the panel) and the active switch.
 * Adding a panel or changing its address, credentials or token stays in the web panel setup. The caller audits.
 */
final class PanelAdministration
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly PanelConnectionTester $tester,
    ) {
    }

    /** Was in PanelSetupController::test() and AdminPanelsHandler::test(). */
    public function test(VpnPanel $panel): PanelConnectionReport
    {
        $report = $this->tester->test($panel);
        $this->entityManager->flush();

        return $report;
    }

    /**
     * Was in AdminPanelsHandler::toggle().
     *
     * @return bool false: the panel already was in that state, nothing done
     */
    public function setActive(VpnPanel $panel, bool $active): bool
    {
        if ($panel->isActive() === $active) {
            return false;
        }

        $panel->setIsActive($active)->setUpdatedAt(new \DateTimeImmutable());
        $this->entityManager->flush();

        return true;
    }
}
