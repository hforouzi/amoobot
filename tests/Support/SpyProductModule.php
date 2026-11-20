<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\Order;
use App\Entity\VpnService;
use App\Module\Application\ProductModuleRegistry;
use App\Module\Domain\ProductModuleInterface;
use App\Provisioning\Application\AutoSuspendSummary;
use App\Provisioning\Application\RenewalResult;
use App\Provisioning\Application\ServiceAdminOutcome;
use App\Provisioning\Application\VpnProductModule;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Test only: wraps a real product module, records every call (method, arguments, result) and passes it on unchanged,
 * so a test pins the path a caller takes without changing what it does. install() puts it, around the container's
 * VpnProductModule, into the registry that ProductModuleResolver reads; call it before the first caller is built.
 */
final class SpyProductModule implements ProductModuleInterface
{
    /** @var list<array{method: string, args: list<mixed>, result: mixed}> */
    public array $calls = [];

    public function __construct(private readonly ProductModuleInterface $inner)
    {
    }

    public static function install(ContainerInterface $container): self
    {
        $real = $container->get('test.module.vpn_product_module');
        \assert($real instanceof VpnProductModule);
        $spy = new self($real);
        $container->set(ProductModuleRegistry::class, new ProductModuleRegistry([$spy]));

        return $spy;
    }

    /** @return list<string> */
    public function methods(): array
    {
        return array_map(static fn (array $call): string => $call['method'], $this->calls);
    }

    public function type(): string
    {
        return $this->inner->type();
    }

    public function contractVersion(): int
    {
        return $this->inner->contractVersion();
    }

    public function provision(Order $order, array $meta = []): VpnService
    {
        return $this->record('provision', [$order, $meta], $this->inner->provision($order, $meta));
    }

    public function renew(VpnService $service, Order $order): RenewalResult
    {
        return $this->record('renew', [$service, $order], $this->inner->renew($service, $order));
    }

    public function suspend(VpnService $service): ServiceAdminOutcome
    {
        return $this->record('suspend', [$service], $this->inner->suspend($service));
    }

    public function resume(VpnService $service): ServiceAdminOutcome
    {
        return $this->record('resume', [$service], $this->inner->resume($service));
    }

    public function terminate(VpnService $service): ServiceAdminOutcome
    {
        return $this->record('terminate', [$service], $this->inner->terminate($service));
    }

    public function runLifecycleTask(string $task, int $limit, bool $dryRun): AutoSuspendSummary
    {
        return $this->record('runLifecycleTask', [$task, $limit, $dryRun], $this->inner->runLifecycleTask($task, $limit, $dryRun));
    }

    /**
     * @template T
     *
     * @param list<mixed> $args
     * @param T           $result
     *
     * @return T
     */
    private function record(string $method, array $args, mixed $result): mixed
    {
        $this->calls[] = ['method' => $method, 'args' => $args, 'result' => $result];

        return $result;
    }
}
