<?php

declare(strict_types=1);

namespace App\Tests\Support;

/**
 * For tests that change tables holding seeded rows (client_app and app_download are seeded by a migration and other
 * tests rely on them): snapshot the tables, and put them back exactly as they were afterwards.
 */
trait RestoresTables
{
    /** @var array<string, list<array<string, mixed>>> */
    private array $tableSnapshots = [];

    protected function snapshotTables(string ...$tables): void
    {
        foreach ($tables as $table) {
            $this->tableSnapshots[$table] = $this->em->getConnection()->fetchAllAssociative('SELECT * FROM '.$table);
        }
    }

    protected function restoreTables(): void
    {
        $connection = $this->em->getConnection();
        $connection->executeStatement('SET FOREIGN_KEY_CHECKS = 0');
        foreach ($this->tableSnapshots as $table => $rows) {
            $connection->executeStatement('DELETE FROM '.$table);
            foreach ($rows as $row) {
                $connection->insert($table, $row);
            }
        }
        $connection->executeStatement('SET FOREIGN_KEY_CHECKS = 1');
        $this->tableSnapshots = [];
    }
}
