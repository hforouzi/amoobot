<?php

declare(strict_types=1);

use App\Admin\Routing\LegacyAdminRoutes;
use App\Admin\UI\LegacyAdminRedirectController;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

// Old admin URLs and route names that moved (see LegacyAdminRoutes).
return static function (RoutingConfigurator $routes): void {
    foreach (LegacyAdminRoutes::REDIRECTS as $name => $redirect) {
        $route = $routes->add($name, $redirect['from'].(($redirect['exact'] ?? false) ? '' : '/{rest}'))
            ->controller(LegacyAdminRedirectController::class);
        if (!($redirect['exact'] ?? false)) {
            $route->defaults(['rest' => ''])->requirements(['rest' => '.*']);
        }

        foreach ($redirect['aliases'] ?? [] as $old => $new) {
            foreach (LegacyAdminRoutes::CRUD_ACTIONS as $action) {
                if ($old.'_'.$action !== $name) {
                    $routes->alias($old.'_'.$action, $new.'_'.$action);
                }
            }
        }
    }
};
