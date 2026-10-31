<?php

declare(strict_types=1);

/*
 * Test helper: holds an exclusive lock on one row of the test database for a while, in its own PHP process (own
 * connection), so a test can make another writer wait for it. Locks the row, creates <readyFile>, sleeps, commits.
 * Usage: php hold-row-lock.php <readyFile> <table> <id> <milliseconds>. Prints "released".
 */

[$container, $entityManager] = require __DIR__.'/boot-test-kernel.php';

[$ready, $table, $id, $milliseconds] = array_slice($argv ?? [], 1) + array_fill(0, 4, '');
if (!in_array($table, ['user', 'reseller', 'wallet'], true)) {
    throw new LogicException('unexpected table');
}
$connection = $entityManager->getConnection();
$connection->beginTransaction();
$connection->executeQuery('SELECT id FROM `'.$table.'` WHERE id = ? FOR UPDATE', [(int) $id]);
touch($ready);
usleep(1000 * (int) $milliseconds);
$connection->commit();
echo 'released';
