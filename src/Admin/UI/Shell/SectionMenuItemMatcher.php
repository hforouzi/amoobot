<?php

declare(strict_types=1);

namespace App\Admin\UI\Shell;

use EasyCorp\Bundle\EasyAdminBundle\Contracts\Menu\MenuItemMatcherInterface;
use EasyCorp\Bundle\EasyAdminBundle\Dto\MenuItemDto;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;
use Symfony\Component\HttpFoundation\Request;

/**
 * Modern layout only: the lit sidebar item is the current page's section (AdminSections), not the item whose URL
 * matches the page, since one item stands for several pages (a wallet page lights "customers"). Classic: EasyAdmin's
 * own matching, unchanged.
 */
#[AsDecorator(decorates: MenuItemMatcherInterface::class)]
final class SectionMenuItemMatcher implements MenuItemMatcherInterface
{
    /** The menu item attribute that names its section (set by DashboardController in modern mode). */
    public const ATTRIBUTE = 'data-admin-section';

    public function __construct(
        #[AutowireDecorated]
        private readonly MenuItemMatcherInterface $inner,
        private readonly AdminUiMode $mode,
    ) {
    }

    /**
     * @param MenuItemDto[] $menuItems
     *
     * @return MenuItemDto[]
     */
    public function markSelectedMenuItem(array $menuItems, Request $request): array
    {
        if (!$this->mode->isModern()) {
            return $this->inner->markSelectedMenuItem($menuItems, $request);
        }

        $section = AdminSections::ofRequest($request);
        foreach ($menuItems as $item) {
            $item->setSelected(null !== $section && $section === ($item->getHtmlAttributes()[self::ATTRIBUTE] ?? null));
        }

        return $menuItems;
    }
}
