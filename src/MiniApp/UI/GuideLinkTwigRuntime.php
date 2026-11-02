<?php

declare(strict_types=1);

namespace App\MiniApp\UI;

use App\MiniApp\Guide\GuideLinks;
use App\Subscription\Domain\ClientPlatform;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Extension\RuntimeExtensionInterface;

/** guide_link() (GuideLinkTwigExtension): the topic's URL in the language of the current Mini App request, or null. */
final class GuideLinkTwigRuntime implements RuntimeExtensionInterface
{
    public function __construct(
        private readonly GuideLinks $links,
        private readonly RequestStack $requestStack,
    ) {
    }

    public function link(string $slug, ?ClientPlatform $platform = null): ?string
    {
        $request = $this->requestStack->getCurrentRequest();

        return $this->links->url($slug, null === $request ? 'fa' : MiniAppLocale::fromRequest($request), $platform);
    }
}
