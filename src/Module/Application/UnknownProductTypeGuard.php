<?php

declare(strict_types=1);

namespace App\Module\Application;

use App\Entity\VpnService;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireServiceClosure;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Fail closed in the lifecycle jobs (docs/design/product-modules.md 3.6, 9 PM2a): a service whose product_type has no
 * installed module is left alone. The job skips the row (no panel call, no status change, no message), keeps going
 * with the others and does not count it as a failure, so the exit code of a run is the one its other rows give.
 *
 * One log line per row and run: "unknown_product_type service_id=<id> type=<type>", no customer data. A run is one
 * process of a job command; app:automation:run, which runs several jobs in one process, calls reset() at its start.
 */
final class UnknownProductTypeGuard implements ResetInterface
{
    /** @var array<int, true> service ids already logged in this run */
    private array $logged = [];

    /**
     * @param \Closure(): ProductModuleRegistry $modules the registry, read on first use: the jobs that use this guard
     *                                                  are part of the 'vpn' module itself, so building the registry
     *                                                  with them would tie it to that moment (and the tests' spy
     *                                                  registry could not be installed)
     */
    public function __construct(
        #[AutowireServiceClosure(ProductModuleRegistry::class)]
        private readonly \Closure $modules,
        private readonly LoggerInterface $logger,
    ) {
    }

    /** True when the job must skip this service: no module of its type. */
    public function skips(VpnService $service): bool
    {
        return $this->skipsRow((int) $service->getId(), $service->getProductType());
    }

    /** The same check for a job that reads rows without loading the entity (service id and vpn_service.product_type). */
    public function skipsRow(int $serviceId, string $productType): bool
    {
        if (($this->modules)()->has($productType)) {
            return false;
        }

        if (!isset($this->logged[$serviceId])) {
            $this->logged[$serviceId] = true;
            $this->logger->warning(sprintf('unknown_product_type service_id=%d type=%s', $serviceId, self::printable($productType)));
        }

        return true;
    }

    public function reset(): void
    {
        $this->logged = [];
    }

    private static function printable(string $type): string
    {
        $clean = preg_replace('/[^\x21-\x7E]/', '?', $type);

        return '' === $clean || null === $clean ? '""' : $clean;
    }
}
