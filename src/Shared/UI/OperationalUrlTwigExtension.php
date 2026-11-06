<?php

declare(strict_types=1);

namespace App\Shared\UI;

use App\Shared\Application\SiteUrls;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * operational_url(route, parameters): url() for a page that stays on DEFAULT_URI, for templates also shown on the brand
 * host (the Mini App), where plain url() would answer with a host that does not serve the page.
 */
final class OperationalUrlTwigExtension extends AbstractExtension
{
    public function __construct(private readonly SiteUrls $urls)
    {
    }

    public function getFunctions(): array
    {
        return [new TwigFunction('operational_url', $this->urls->operational(...))];
    }
}
