<?php

declare(strict_types=1);

namespace App\MiniApp\UI;

use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * guide_link(slug[, platform]): the Mini App URL of a guide topic's card, or null when that topic is not shown right now
 * (GuideLinks), so a view links only to a visible topic. Used through templates/miniapp/view/_guide_link.html.twig. The
 * work is in GuideLinkTwigRuntime (lazy: the guide itself renders with Twig).
 */
final class GuideLinkTwigExtension extends AbstractExtension
{
    public function getFunctions(): array
    {
        return [new TwigFunction('guide_link', [GuideLinkTwigRuntime::class, 'link'])];
    }
}
