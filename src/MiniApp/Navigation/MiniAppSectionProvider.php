<?php

declare(strict_types=1);

namespace App\MiniApp\Navigation;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Registers Mini App sections. Any service implementing it is picked up (tag app.miniapp_section_provider), so a
 * new area (admin screens, resellers) adds a provider and its routes; the shell and the navigation stay as they are.
 */
#[AutoconfigureTag('app.miniapp_section_provider')]
interface MiniAppSectionProvider
{
    /**
     * @return iterable<MiniAppSection>
     */
    public function sections(): iterable;
}
