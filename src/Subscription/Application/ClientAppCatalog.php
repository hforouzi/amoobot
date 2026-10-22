<?php

declare(strict_types=1);

namespace App\Subscription\Application;

use App\Entity\ClientApp;
use App\Subscription\Domain\ClientPlatform;
use App\Subscription\Domain\ImportLinkTemplate;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The active client apps for the subscription page: the ones for the visitor's platform first (each group in the
 * admin's sort order), each with its import deep link for this subscription URL and its install link.
 */
final class ClientAppCatalog
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly AppDownloadCatalog $downloads,
    ) {
    }

    /**
     * @return list<ClientAppView>
     */
    public function forPage(string $subscriptionUrl, string $profileName, ?ClientPlatform $platform): array
    {
        return $this->views($platform, static fn (ClientApp $app): ?string => ImportLinkTemplate::build($app->getImportUrlTemplate(), $subscriptionUrl, $profileName));
    }

    /**
     * The same apps without import links (no subscription involved): what to install, e.g. on the Mini App's apps page.
     *
     * @return list<ClientAppView>
     */
    public function forCatalog(?ClientPlatform $platform): array
    {
        return $this->views($platform, static fn (): ?string => null);
    }

    /**
     * @param callable(ClientApp): ?string $importUrl
     *
     * @return list<ClientAppView>
     */
    private function views(?ClientPlatform $platform, callable $importUrl): array
    {
        /** @var list<ClientApp> $apps */
        $apps = $this->entityManager->getRepository(ClientApp::class)->findBy(['isActive' => true], ['sortOrder' => 'ASC', 'name' => 'ASC', 'id' => 'ASC']);
        $activeDownloads = $this->downloads->active($platform);

        $views = [];
        foreach ($apps as $app) {
            $matches = null !== $platform && $app->supports($platform);
            $download = AppDownloadCatalog::forApp($activeDownloads, (int) $app->getId(), $matches ? $platform : null);
            $views[] = new ClientAppView(
                name: $app->getName(),
                icon: $app->getIcon(),
                platforms: $app->getPlatforms(),
                importUrl: $importUrl($app),
                installUrl: null !== $download ? $this->downloads->path($download) : $app->getStoreUrl(),
                matchesPlatform: $matches,
            );
        }

        // Stable: matching apps first, the admin's order inside each group.
        $matching = array_values(array_filter($views, static fn (ClientAppView $v): bool => $v->matchesPlatform));
        $others = array_values(array_filter($views, static fn (ClientAppView $v): bool => !$v->matchesPlatform));

        return [...$matching, ...$others];
    }
}
