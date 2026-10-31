<?php

declare(strict_types=1);

/*
 * Test helper: another process that needs an order's row while a settlement holds it (a second callback, an admin's
 * second click). It waits until the row is locked by someone else (FOR UPDATE NOWAIT refused), then takes it with a
 * plain FOR UPDATE under innodb_lock_wait_timeout = <lockWaitSeconds> and prints one JSON line:
 * {"locked_seen_after": s, "waited": s, "ok": bool, "error": "..."}.
 *
 * Usage: php lock-order-row.php <orderId> <lockWaitSeconds>
 */

[, $entityManager] = require __DIR__.'/boot-test-kernel.php';

[$orderId, $lockWait] = array_map('intval', array_slice($argv ?? [], 1, 2));
$db = $entityManager->getConnection();
$db->executeStatement('SET SESSION innodb_lock_wait_timeout = '.max(1, $lockWait));

$started = microtime(true);
$seen = null;
while (microtime(true) - $started < 180) {
    $db->beginTransaction();
    try {
        $db->fetchOne('SELECT id FROM `order` WHERE id = ? FOR UPDATE NOWAIT', [$orderId]);
        $db->rollBack();
    } catch (Throwable) {
        $db->rollBack();
        $seen = microtime(true);
        break;
    }
    usleep(50_000);
}
if (null === $seen) {
    echo json_encode(['locked_seen_after' => null, 'waited' => 0, 'ok' => false, 'error' => 'the row was never locked within 180 s']), "\n";
    exit(1);
}

$from = microtime(true);
$db->beginTransaction();
try {
    $db->fetchOne('SELECT id FROM `order` WHERE id = ? FOR UPDATE', [$orderId]);
    $db->rollBack();
    $result = ['ok' => true, 'error' => null];
} catch (Throwable $e) {
    $db->isTransactionActive() && $db->rollBack();
    $result = ['ok' => false, 'error' => mb_substr($e->getMessage(), 0, 200)];
}
echo json_encode(['locked_seen_after' => round($seen - $started, 2), 'waited' => round(microtime(true) - $from, 2)] + $result), "\n";
