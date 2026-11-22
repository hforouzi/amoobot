<?php

declare(strict_types=1);

namespace App\Plugin;

use App\Entity\Plugin;
use App\Plugin\Driver\DriverPluginDoctor;

/**
 * The doctor of a plugin's type, in the shape the admin plugin page reads: the payment doctor for a gateway (unchanged),
 * the driver doctor for a driver package, and nothing to inspect for a core row (built in: no files, no class).
 */
final class PluginDoctorDispatcher
{
    public function __construct(
        private readonly PaymentPluginDoctor $paymentDoctor,
        private readonly DriverPluginDoctor $driverDoctor,
    ) {
    }

    public function inspect(?Plugin $plugin): PluginDoctorResult
    {
        if ($plugin instanceof Plugin && $plugin->isCore()) {
            return new PluginDoctorResult(true, $plugin->getStatus(), $plugin->getType(), '', '', '', '', '', true, '', true, true, [], []);
        }
        if ($plugin instanceof Plugin && $plugin->isDriver()) {
            return $this->driverDoctor->inspect($plugin)->toPluginDoctorResult();
        }

        return $this->paymentDoctor->inspect($plugin);
    }
}
