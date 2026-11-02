<?php

declare(strict_types=1);

namespace App\MiniApp\Guide;

/**
 * One client app in the guide's "connect" topic (GuideClientApps): its name and icon as the admin typed them (escaped
 * by Twig), its install link (a /d/{id} path or a validated http(s) store URL, or null: no button) and whether it has
 * a one-tap import button on the service pages (the guide only points there).
 */
final readonly class GuideClientApp
{
    public function __construct(
        public string $name,
        public ?string $icon,
        public ?string $installUrl,
        public bool $hasImportButton,
    ) {
    }
}
