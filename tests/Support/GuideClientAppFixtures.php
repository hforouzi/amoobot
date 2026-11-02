<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\AppDownload;
use App\Entity\ClientApp;

/**
 * Client apps for the guide tests: client_app and app_download are not purged between tests (the migration seeds
 * apps), so a test switches the existing ones off, adds its own, and restoreClientApps() (tearDown) deletes what it
 * added and switches the others back to how they were. Needs IntegrationTestCase::$em.
 */
trait GuideClientAppFixtures
{
    /** @var array<int, bool>|null client_app id => is_active before the test */
    private ?array $savedClientApps = null;
    /** @var array<int, bool> app_download id => is_active before the test */
    private array $savedDownloads = [];
    private int $savedMaxClientAppId = 0;
    private int $savedMaxDownloadId = 0;

    protected function switchOffClientApps(): void
    {
        $connection = $this->em->getConnection();
        if (null === $this->savedClientApps) {
            $this->savedClientApps = [];
            foreach ($connection->fetchAllAssociative('SELECT id, is_active FROM client_app') as $row) {
                $this->savedClientApps[(int) $row['id']] = (bool) $row['is_active'];
            }
            $this->savedMaxClientAppId = (int) $connection->fetchOne('SELECT COALESCE(MAX(id), 0) FROM client_app');
            $this->savedMaxDownloadId = (int) $connection->fetchOne('SELECT COALESCE(MAX(id), 0) FROM app_download');
            foreach ($connection->fetchAllAssociative('SELECT id, is_active FROM app_download') as $row) {
                $this->savedDownloads[(int) $row['id']] = (bool) $row['is_active'];
            }
            $connection->executeStatement('UPDATE app_download SET is_active = 0');
        }
        $connection->executeStatement('UPDATE client_app SET is_active = 0');
    }

    /**
     * @param list<string> $platforms
     */
    protected function addClientApp(string $name, array $platforms, int $sortOrder, ?string $storeUrl = null, bool $active = true, ?string $importTemplate = null, ?string $icon = null): ClientApp
    {
        if (null === $this->savedClientApps) {
            $this->switchOffClientApps();
        }
        $app = (new ClientApp())->setName($name)->setPlatforms($platforms)->setSortOrder($sortOrder)->setIsActive($active)
            ->setImportUrlTemplate($importTemplate)->setIcon($icon);
        $this->em->persist($app);
        $this->em->flush();
        // Straight into the column: also values the admin form would refuse (a database edited by hand).
        $this->em->getConnection()->update('client_app', ['store_url' => $storeUrl], ['id' => $app->getId()]);
        $this->em->refresh($app);

        return $app;
    }

    protected function addDownload(ClientApp $app, string $platform, ?string $externalUrl, bool $active = true): AppDownload
    {
        $download = (new AppDownload())->setTitle($app->getName().' '.$platform)->setPlatform($platform)
            ->setType(AppDownload::TYPE_URL)->setClientApp($app)->setIsActive($active);
        $this->em->persist($download);
        $this->em->flush();
        $this->em->getConnection()->update('app_download', ['external_url' => $externalUrl], ['id' => $download->getId()]);
        $this->em->refresh($download);

        return $download;
    }

    protected function restoreClientApps(): void
    {
        if (null === $this->savedClientApps || !isset($this->em) || !$this->em->isOpen()) {
            return;
        }
        $connection = $this->em->getConnection();
        $connection->executeStatement('DELETE FROM app_download WHERE id > ?', [$this->savedMaxDownloadId]);
        $connection->executeStatement('DELETE FROM app_download WHERE client_app_id > ?', [$this->savedMaxClientAppId]);
        $connection->executeStatement('DELETE FROM client_app WHERE id > ?', [$this->savedMaxClientAppId]);
        foreach ($this->savedClientApps as $id => $active) {
            $connection->update('client_app', ['is_active' => $active ? 1 : 0], ['id' => $id]);
        }
        foreach ($this->savedDownloads as $id => $active) {
            $connection->update('app_download', ['is_active' => $active ? 1 : 0], ['id' => $id]);
        }
        $this->savedClientApps = null;
        $this->savedDownloads = [];
    }
}
