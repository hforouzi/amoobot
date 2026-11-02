<?php

declare(strict_types=1);

namespace App\MiniApp\Guide;

use App\Entity\AppDownload;
use App\Entity\ClientApp;
use App\Subscription\Application\AppDownloadCatalog;
use App\Subscription\Domain\ClientPlatform;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The client apps of the guide's "connect" topic (docs/design/help-guide.md, section 4.2): for one platform, the active
 * ClientApp records the admin set up for it, in the admin's sort order (the same order as the subscription page); the
 * first one is the recommended app. Every install link comes from the admin's data only: the app's active download for
 * that platform (through the /d/{id} counter, and only when a link download points at an http(s) URL), else the app's
 * store URL when it is a plain http(s) URL. Nothing of one customer: no subscription link, no import link (those stay
 * on the service pages), no tracking parameter. App names live in the admin's data, never in code.
 */
final class GuideClientApps
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly AppDownloadCatalog $downloads,
    ) {
    }

    /**
     * @return list<GuideClientApp> the platform's apps, the recommended one first; [] for an unknown platform
     */
    public function forPlatform(?ClientPlatform $platform): array
    {
        if (null === $platform) {
            return [];
        }
        $downloads = $this->downloads->active($platform);
        $apps = [];
        foreach ($this->activeApps() as $app) {
            if (!$app->supports($platform) || '' === trim($app->getName())) {
                continue;
            }
            $apps[] = new GuideClientApp(
                name: $app->getName(),
                icon: $app->getIcon(),
                installUrl: $this->installUrl($app, $downloads, $platform),
                hasImportButton: null !== $app->getImportUrlTemplate(),
            );
        }

        return $apps;
    }

    /** Whether any client app is active, for any platform (none: the guide shows no device switch at all). */
    public function any(): bool
    {
        return [] !== $this->activeApps();
    }

    /**
     * The platforms no active app is set up for: the guide shows only the generic text there (the admin's warning).
     *
     * @return list<ClientPlatform>
     */
    public function platformsWithoutApps(): array
    {
        $apps = $this->activeApps();

        return array_values(array_filter(ClientPlatform::cases(), static function (ClientPlatform $platform) use ($apps): bool {
            foreach ($apps as $app) {
                if ($app->supports($platform) && '' !== trim($app->getName())) {
                    return false;
                }
            }

            return true;
        }));
    }

    /**
     * An absolute http(s) URL with a host and nothing that could break out of an attribute or carry credentials.
     * (The admin form already validates store and download URLs; this also covers a value edited in the database.)
     */
    public static function isSafeInstallUrl(?string $url): bool
    {
        if (null === $url || '' === $url || 1 === preg_match('/[\s"\'<>\\\\`]|[^\x21-\x7E]/', $url)) {
            return false;
        }
        if (false === filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }
        $parts = parse_url($url);

        return is_array($parts) && in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
            && '' !== ($parts['host'] ?? '') && !isset($parts['user']) && !isset($parts['pass']);
    }

    /**
     * @param list<AppDownload> $downloads
     */
    private function installUrl(ClientApp $app, array $downloads, ClientPlatform $platform): ?string
    {
        $download = AppDownloadCatalog::forApp($downloads, (int) $app->getId(), $platform);
        if (null !== $download && ($download->isFile() || self::isSafeInstallUrl($download->getExternalUrl()))) {
            return $this->downloads->path($download);
        }
        $store = $app->getStoreUrl();

        return self::isSafeInstallUrl($store) ? $store : null;
    }

    /**
     * @return list<ClientApp>
     */
    private function activeApps(): array
    {
        /** @var list<ClientApp> $apps */
        $apps = $this->entityManager->getRepository(ClientApp::class)->findBy(['isActive' => true], ['sortOrder' => 'ASC', 'name' => 'ASC', 'id' => 'ASC']);

        return $apps;
    }
}
