<?php

declare(strict_types=1);

/*
 * Shared start of the test helpers in this directory (each runs in its own PHP process so a test can race two of
 * them). Returns [test service container, entity manager].
 *
 * Both the kernel boot (it may rebuild the cached container) and the Doctrine proxies (auto_generate_proxy_classes
 * rewrites a proxy file the first time a process needs it) write into var/cache. Two processes doing that at once
 * collide on Windows ("rename(): Access is denied") and the test fails at random. So the boot and the loading of
 * EVERY proxy class happen under one file lock, before the actual (parallel) work starts; afterwards the process
 * never writes the cache again.
 */

use App\Kernel;
use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__, 3).'/vendor/autoload.php';

$_SERVER['APP_ENV'] = $_ENV['APP_ENV'] = 'test';
(new Dotenv())->bootEnv(dirname(__DIR__, 3).'/.env');

// Quiet, like the PHPUnit process (phpunit.dist.xml: SHELL_VERBOSITY=-1, which Symfony Process does not pass on: it
// is only in $_SERVER). Without it the debug kernel sets SHELL_VERBOSITY=3 on boot and the framework's fallback logger
// (no MonologBundle here) writes every debug/info line through error_log(): into a file where php.ini sets error_log,
// but onto stderr where it does not (CI). -1 keeps errors only.
putenv('SHELL_VERBOSITY=-1');
$_ENV['SHELL_VERBOSITY'] = $_SERVER['SHELL_VERBOSITY'] = -1;

$lock = fopen(sys_get_temp_dir().'/amoobot-test-proxies.lock', 'c') ?: throw new RuntimeException('Cannot open the proxy lock file.');
flock($lock, LOCK_EX);
try {
    // debug=true: rebuild the cached container when sources change (same as the PHPUnit kernel).
    $kernel = new Kernel('test', true);
    $kernel->boot();
    $container = $kernel->getContainer()->get('test.service_container');
    $entityManager = $container->get('doctrine')->getManager();
    foreach ($entityManager->getMetadataFactory()->getAllMetadata() as $metadata) {
        if (!$metadata->isMappedSuperclass && !$metadata->isEmbeddedClass && 1 === count($metadata->getIdentifierFieldNames())) {
            $entityManager->getProxyFactory()->getProxy($metadata->getName(), [$metadata->getIdentifierFieldNames()[0] => 0]);
        }
    }
} finally {
    flock($lock, LOCK_UN);
    fclose($lock);
}

return [$container, $entityManager];
