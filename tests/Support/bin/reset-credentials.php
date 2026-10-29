<?php

declare(strict_types=1);

/*
 * Test helper: the database step of a credential reset (ServiceOwnership::resetConnectionLinks after the panel call),
 * in its own PHP process: locks the vpn_service row by primary key, writes the new client UUID, subId, remote id and
 * the new credentials' links, creates <lockedFile>, keeps the lock for <holdMs>, commits.
 * Usage: php reset-credentials.php <serviceId> <newUuid> <newSubId> <holdMs> <lockedFile>. Prints "reset".
 */

use App\Provisioning\Infrastructure\Sanaei3xui\Sanaei3xuiRemoteIdParser;

[$container, $entityManager] = require __DIR__.'/boot-test-kernel.php';

[$serviceId, $newUuid, $newSubId, $holdMs, $locked] = array_slice($argv, 1) + array_fill(0, 5, '');
$connection = $entityManager->getConnection();
$connection->executeStatement('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
$connection->beginTransaction();
$row = $connection->fetchAssociative('SELECT remote_id FROM vpn_service WHERE id = ? FOR UPDATE', [(int) $serviceId]) ?: throw new RuntimeException('no such service');
$parser = new Sanaei3xuiRemoteIdParser();
$ref = $parser->parse((string) $row['remote_id']) ?? throw new RuntimeException('unreadable remote id');
$links = [sprintf('vless://%s@cdn1.example.com:443?type=tcp&security=reality#reset', $newUuid)];
$connection->executeStatement(
    'UPDATE vpn_service SET client_uuid = ?, sub_id = ?, remote_id = ?, config_links = ?, config_text = ? WHERE id = ?',
    [$newUuid, $newSubId, $parser->format($ref->panelId, $ref->localInboundId, $ref->inboundId, $newUuid, $ref->email, $newSubId), json_encode($links), implode("\n", $links), (int) $serviceId],
);
touch($locked);
usleep(1000 * (int) $holdMs);
$connection->commit();
echo 'reset';
