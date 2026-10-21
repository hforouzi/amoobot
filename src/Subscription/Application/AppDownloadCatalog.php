<?php

declare(strict_types=1);

namespace App\Subscription\Application;

use App\Entity\AppDownload;
use App\Subscription\Domain\ClientPlatform;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Active app downloads, for the subscription page, the bot and client apps' "Install app" buttons.
 */
final class AppDownloadCatalog
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    /**
     * Ordered by platform (the visitor's first, then the platform list order), then the admin's sort order.
     *
     * @return list<AppDownload>
     */
    public function active(?ClientPlatform $first = null): array
    {
        /** @var list<AppDownload> $downloads */
        $downloads = $this->entityManager->getRepository(AppDownload::class)->findBy(['isActive' => true], ['sortOrder' => 'ASC', 'title' => 'ASC', 'id' => 'ASC']);
        $rank = array_flip(ClientPlatform::ordered($first));
        usort($downloads, static fn (AppDownload $a, AppDownload $b): int => [$rank[$a->getPlatform()] ?? 99, $a->getSortOrder()] <=> [$rank[$b->getPlatform()] ?? 99, $b->getSortOrder()]);

        return $downloads;
    }

    public function hasActive(): bool
    {
        return null !== $this->entityManager->getRepository(AppDownload::class)->findOneBy(['isActive' => true]);
    }

    /**
     * @return list<AppDownloadView>
     */
    public function forPage(?ClientPlatform $platform): array
    {
        return array_map(fn (AppDownload $d): AppDownloadView => new AppDownloadView(
            id: (int) $d->getId(),
            title: $d->getTitle(),
            platform: $d->getPlatform(),
            version: $d->getVersion(),
            description: $d->getDescription(),
            sizeBytes: $d->isFile() ? $d->getSizeBytes() : null,
            sha256: $d->isFile() ? $d->getSha256() : null,
            url: $this->path($d),
            isExternal: !$d->isFile(),
            matchesPlatform: null !== $platform && $platform->value === $d->getPlatform(),
        ), $this->active($platform));
    }

    /**
     * The first active download of this app for the platform (or for any of the app's platforms when unknown).
     *
     * @param list<AppDownload> $active
     */
    public static function forApp(array $active, int $clientAppId, ?ClientPlatform $platform): ?AppDownload
    {
        foreach ($active as $download) {
            if ($clientAppId === $download->getClientApp()?->getId() && (null === $platform || $platform->value === $download->getPlatform())) {
                return $download;
            }
        }

        return null;
    }

    public function path(AppDownload $download): string
    {
        return $this->urlGenerator->generate('app_download', ['id' => $download->getId()]);
    }

    public function absoluteUrl(AppDownload $download): string
    {
        return $this->urlGenerator->generate('app_download', ['id' => $download->getId()], UrlGeneratorInterface::ABSOLUTE_URL);
    }
}

