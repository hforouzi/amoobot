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
                importUrl: ImportLinkTemplate::build($app->getImportUrlTemplate(), $subscriptionUrl, $profileName),
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
