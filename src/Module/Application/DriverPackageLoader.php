<?php

declare(strict_types=1);

namespace App\Module\Application;

use App\Entity\Plugin;
use App\Module\Domain\ProviderDriverInterface;
use App\Plugin\Driver\DriverPluginDoctor;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * The enabled driver packages, built for ProviderDriverRegistry (docs/design/product-modules.md 4.2, PM2b). A package
 * is loaded only when:
 *
 *  - its row is an enabled 'package' row of a driver type;
 *  - its module is in APP_MODULES_ALLOWED, and the module's own row (if there is one) is enabled;
 *  - DriverPluginDoctor passes now: manifest, core version, contract version, file hashes, then the class and its
 *    capabilities. The hashes are checked again on every load, so a file changed after enable is caught.
 *
 * A package that fails the doctor is set to 'error' with the reason (one UPDATE of its row, not through the
 * EntityManager, so nothing else pending is flushed) and is not loaded: its driver fails closed. Core drivers are not
 * read from here: they stay tagged services, whatever their row says (gating by row is PM3b).
 */
final class DriverPackageLoader
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly DriverPluginDoctor $doctor,
        private readonly ModuleAllowList $allowList,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @return list<ProviderDriverInterface>
     */
    public function load(): array
    {
        $repository = $this->entityManager->getRepository(Plugin::class);
        $rows = $repository->findBy([
            'type' => array_keys(Plugin::DRIVER_TYPES),
            'status' => Plugin::STATUS_ENABLED,
            'source' => Plugin::SOURCE_PACKAGE,
        ], ['code' => 'ASC']);

        $drivers = [];
        foreach ($rows as $plugin) {
            $module = (string) $plugin->module();
            if (!$this->allowList->allows($module)) {
                $this->logger->warning(sprintf('driver_package_skipped code=%s reason=module_not_allowed module=%s', $plugin->getCode(), $module));
                continue;
            }
            $moduleRow = $repository->findOneBy(['code' => $module, 'type' => Plugin::TYPE_PRODUCT_MODULE]);
            if ($moduleRow instanceof Plugin && Plugin::STATUS_ENABLED !== $moduleRow->getStatus()) {
                $this->logger->info(sprintf('driver_package_skipped code=%s reason=module_disabled module=%s', $plugin->getCode(), $module));
                continue;
            }

            $result = $this->doctor->inspect($plugin);
            if (!$result->ok() || null === $result->driver) {
                $this->markError($plugin, $result->errorMessage());
                continue;
            }
            $drivers[] = $result->driver;
        }

        return $drivers;
    }

    private function markError(Plugin $plugin, string $reason): void
    {
        $this->logger->error(sprintf('driver_package_refused code=%s reason=%s', $plugin->getCode(), $reason));
        $this->entityManager->getConnection()->executeStatement(
            "UPDATE plugin SET status = 'error', error_message = ?, updated_at = NOW() WHERE id = ? AND status = 'enabled'",
            [$reason, $plugin->getId()],
        );
    }
}
