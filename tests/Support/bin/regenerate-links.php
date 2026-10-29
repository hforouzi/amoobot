<?php

declare(strict_types=1);

/*
 * Test helper: one regenerate path of a modern 3x-ui service in its own PHP process, against a fake panel whose
 * subLinks answer for the service's current subId blocks in the middle of the HTTP call: it creates <readyFile>,
 * records whether the service row could be locked by another connection at that moment (so: whether this process
 * holds a lock or a transaction during the panel call) into <probeFile>, then waits for <goFile>. It then answers with
 * links of the credentials the service had when it was read. subLinks for <newSubId> answers with links of <newUuid>.
 *
 * Usage: php regenerate-links.php <button|single|bulk> <serviceId> <inboundId> <newUuid> <newSubId> <readyFile> <goFile> <probeFile>
 * Prints the outcome (button: ServiceConfigRegeneration outcome; single/bulk: "exit=<code>" and the command output).
 */

use App\Entity\VpnService;
use App\Provisioning\Application\ServiceConfigRegenerator;
use App\Tests\Support\Fake3xui\Fake3xuiPanel;
use App\Tests\Support\MockHttpResponder;
use Doctrine\DBAL\DriverManager;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

[$container, $entityManager] = require __DIR__.'/boot-test-kernel.php';

[$mode, $serviceId, $inboundId, $newUuid, $newSubId, $ready, $go, $probe] = array_slice($argv ?? [], 1) + array_fill(0, 8, '');
$service = $entityManager->find(VpnService::class, (int) $serviceId) ?? throw new RuntimeException('no such service');
$oldUuid = (string) $service->getClientUuid();
$oldSubId = (string) $service->getSubId();
$link = static fn (string $uuid, string $host): string => sprintf('vless://%s@%s:443?type=tcp&security=reality#%s', $uuid, $host, $host);

$fake = new Fake3xuiPanel();
$own = $entityManager->getConnection();
$fake->script('GET', 'panel/api/clients/subLinks/'.$oldSubId, static function () use ($ready, $go, $probe, $own, $serviceId, $oldUuid, $link) {
    touch($ready);
    // Another connection tries the row without waiting: it gets it unless this process holds it.
    $other = DriverManager::getConnection($own->getParams());
    try {
        $other->beginTransaction();
        $other->fetchOne('SELECT id FROM vpn_service WHERE id = ? FOR UPDATE NOWAIT', [(int) $serviceId]);
        $other->rollBack();
        $rowFree = 'row-free';
    } catch (Throwable) {
        $rowFree = 'row-LOCKED';
    }
    $other->close();
    file_put_contents($probe, $rowFree.' '.($own->isTransactionActive() ? 'tx-OPEN' : 'no-tx')."\n", FILE_APPEND);
    for ($i = 0; $i < 400 && !is_file($go); ++$i) {
        usleep(25_000);
    }

    return Fake3xuiPanel::envelope(true, [$link($oldUuid, 'cdn1.example.com'), $link($oldUuid, 'cdn2.example.com')]);
}, 1);
$fake->script('GET', 'panel/api/clients/subLinks/'.$newSubId, Fake3xuiPanel::envelope(true, [$link($newUuid, 'cdn1.example.com'), $link($newUuid, 'cdn2.example.com')]), 5);
$container->get(MockHttpResponder::class)->on('panel.example.test', $fake);

if ('button' === $mode) {
    echo $container->get(ServiceConfigRegenerator::class)->regenerate($service, 'race_test')->outcome;

    return;
}
// boot-test-kernel.php makes the process quiet; the command's own output is what the test reads.
putenv('SHELL_VERBOSITY=0');
$_ENV['SHELL_VERBOSITY'] = $_SERVER['SHELL_VERBOSITY'] = 0;
$application = new Application($container->get('kernel'));
$application->setAutoExit(false);
$input = 'single' === $mode
    ? ['command' => 'app:service:regenerate-config', 'serviceId' => $serviceId, '--apply' => true]
    : ['command' => 'app:inbound:regenerate-service-configs', 'inboundId' => $inboundId, '--apply' => true];
$output = new BufferedOutput();
$code = $application->run(new ArrayInput($input), $output);
echo 'exit='.$code."\n".$output->fetch();
