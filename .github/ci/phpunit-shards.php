<?php

declare(strict_types=1);

/*
 * PHPUnit shard map for .github/workflows/ci.yml.
 *
 *   php .github/ci/phpunit-shards.php matrix          Check the map covers every test file, print the job matrix (JSON).
 *   php .github/ci/phpunit-shards.php check           Same check, human-readable (files per shard).
 *   php .github/ci/phpunit-shards.php config <shard>  Write phpunit.shard-<shard>.xml (phpunit.dist.xml with only that shard's tests).
 *   php .github/ci/phpunit-shards.php verify          Compare `phpunit --list-tests` of every shard with the full suite:
 *                                                     each test must be listed by exactly one shard (needs vendor/).
 *
 * A test file belongs to the shard owning the LONGEST path that contains it, so a subdirectory can be given to another
 * shard than its parent (tests/Shop/InboundMove vs tests/Shop). Every *Test.php under the suite directories of
 * phpunit.dist.xml must belong to a shard, otherwise `check`/`matrix` fail: a new test directory cannot be skipped silently.
 *
 * Balanced by measured runtime (develop/v3 run 44799254634, phpunit 15:24 serial): approx. seconds in comments.
 * Rebalance with the junit-* artifacts each shard uploads.
 */

const SHARDS = [
    // ~250s
    'shop-inbound-move' => [
        'faketime' => true, // tests/Clock/MidnightCrossingTest runs under libfaketime.
        'paths' => ['tests/Shop/InboundMove', 'tests/Bot', 'tests/Clock', 'tests/Subscription', 'tests/Shared', 'tests/Broadcast'],
    ],
    // ~140s: the multi-process / lock tests (parallel settlement, wallet locks) run alone.
    'payment-wallet' => [
        'faketime' => false,
        'paths' => ['tests/Payment', 'tests/Wallet'],
    ],
    // ~255s
    'reseller-admin' => [
        'faketime' => false,
        'paths' => ['tests/Reseller', 'tests/Admin', 'tests/Provisioning', 'tests/Security'],
    ],
    // ~280s
    'shop-miniapp' => [
        'faketime' => false,
        'paths' => [
            'tests/Shop', 'tests/Command', 'tests/MiniApp', 'tests/Branding', 'tests/Module', 'tests/Contract',
            'tests/Database', 'tests/Support',
            'docs/plugins/winapay-gateway/tests', 'docs/plugins/tetraminator-gateway/tests', 'docs/plugins/hooshpay-gateway/tests',
        ],
    ],
];

$root = dirname(__DIR__, 2);
chdir($root);

function fail(string $message): never
{
    fwrite(STDERR, "ERROR: {$message}\n");
    exit(1);
}

function distConfig(): DOMDocument
{
    $dom = new DOMDocument();
    $dom->preserveWhiteSpace = true;
    if (!$dom->load('phpunit.dist.xml')) {
        fail('cannot read phpunit.dist.xml');
    }

    return $dom;
}

/** @return list<string> the suite directories of phpunit.dist.xml */
function suiteDirectories(): array
{
    $dirs = [];
    foreach ((new DOMXPath(distConfig()))->query('/phpunit/testsuites/testsuite/directory') as $node) {
        $dirs[] = rtrim(trim($node->textContent), '/');
    }
    if (!$dirs) {
        fail('phpunit.dist.xml has no testsuite directories');
    }

    return $dirs;
}

/** @return array<string, string> path => shard */
function pathOwners(): array
{
    $owners = [];
    foreach (SHARDS as $shard => $def) {
        foreach ($def['paths'] as $path) {
            if (isset($owners[$path])) {
                fail("{$path} is listed in shard {$owners[$path]} and in shard {$shard}");
            }
            if (!is_dir($path)) {
                fail("shard {$shard}: {$path} does not exist");
            }
            $owners[$path] = $shard;
        }
    }

    return $owners;
}

function ownerOf(string $file, array $owners): ?string
{
    $best = null;
    foreach (array_keys($owners) as $path) {
        if (str_starts_with($file, $path.'/') && (null === $best || strlen($path) > strlen($best))) {
            $best = $path;
        }
    }

    return null === $best ? null : $owners[$best];
}

/** @return array<string, list<string>> shard => test files; fails on any unassigned file */
function assignFiles(): array
{
    $owners = pathOwners();
    $suites = suiteDirectories();
    foreach (array_keys($owners) as $path) {
        $inSuite = false;
        foreach ($suites as $suite) {
            $inSuite = $inSuite || $path === $suite || str_starts_with($path, $suite.'/');
        }
        if (!$inSuite) {
            fail("{$path} is not inside a testsuite directory of phpunit.dist.xml");
        }
    }

    $byShard = array_fill_keys(array_keys(SHARDS), []);
    $unassigned = [];
    foreach ($suites as $suite) {
        if (!is_dir($suite)) {
            fail("phpunit.dist.xml directory {$suite} does not exist");
        }
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($suite, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            // PHPUnit's default suffix: only these files are collected from a <directory>.
            if (!$file->isFile() || !str_ends_with($file->getFilename(), 'Test.php')) {
                continue;
            }
            $relative = str_replace('\\', '/', $file->getPathname());
            $shard = ownerOf($relative, $owners);
            if (null === $shard) {
                $unassigned[] = $relative;
            } else {
                $byShard[$shard][] = $relative;
            }
        }
    }
    if ($unassigned) {
        sort($unassigned);
        fail("test files in no shard (add their directory to SHARDS in .github/ci/phpunit-shards.php):\n  ".implode("\n  ", $unassigned));
    }
    foreach ($byShard as $shard => $files) {
        if (!$files) {
            fail("shard {$shard} has no test files");
        }
    }

    return $byShard;
}

function writeConfig(string $shard): string
{
    if (!isset(SHARDS[$shard])) {
        fail("unknown shard {$shard}");
    }
    $owners = pathOwners();
    $dom = distConfig();
    $xpath = new DOMXPath($dom);
    $testsuites = $xpath->query('/phpunit/testsuites')->item(0);
    if (!$testsuites instanceof DOMElement) {
        fail('phpunit.dist.xml has no <testsuites>');
    }
    while ($testsuites->firstChild) {
        $testsuites->removeChild($testsuites->firstChild);
    }
    $suite = $dom->createElement('testsuite');
    $suite->setAttribute('name', 'shard-'.$shard);
    foreach (SHARDS[$shard]['paths'] as $path) {
        $suite->appendChild($dom->createElement('directory', $path));
    }
    // Deeper paths owned by another shard (tests/Shop/InboundMove inside tests/Shop) are excluded here.
    foreach (SHARDS[$shard]['paths'] as $path) {
        foreach ($owners as $other => $otherShard) {
            if ($otherShard !== $shard && str_starts_with($other, $path.'/')) {
                $suite->appendChild($dom->createElement('exclude', $other));
            }
        }
    }
    $testsuites->appendChild($suite);

    $file = "phpunit.shard-{$shard}.xml";
    if (false === $dom->save($file)) {
        fail("cannot write {$file}");
    }

    return $file;
}

/** @return list<string> */
function listTests(string $config): array
{
    exec(escapeshellarg(PHP_BINARY).' bin/phpunit -c '.escapeshellarg($config).' --list-tests 2>&1', $out, $code);
    if (0 !== $code) {
        fail("phpunit --list-tests -c {$config} failed ({$code}):\n".implode("\n", $out));
    }
    $tests = [];
    foreach ($out as $line) {
        if (str_starts_with($line, ' - ')) {
            $tests[] = substr($line, 3);
        }
    }

    return $tests;
}

$command = $argv[1] ?? '';
switch ($command) {
    case 'check':
        $total = 0;
        foreach (assignFiles() as $shard => $files) {
            printf("%-20s %4d test files\n", $shard, count($files));
            $total += count($files);
        }
        printf("%-20s %4d test files, each in exactly one shard\n", 'total', $total);
        break;

    case 'matrix':
        assignFiles();
        $include = [];
        foreach (SHARDS as $shard => $def) {
            $include[] = ['shard' => $shard, 'faketime' => $def['faketime']];
        }
        echo json_encode(['include' => $include], JSON_THROW_ON_ERROR), "\n";
        break;

    case 'config':
        echo writeConfig($argv[2] ?? fail('usage: config <shard>')), "\n";
        break;

    case 'verify':
        assignFiles();
        // Some names are listed more than once (repeated tests), so compare as multisets: name => count.
        $full = array_count_values(listTests('phpunit.dist.xml'));
        $owner = [];
        $sum = 0;
        foreach (array_keys(SHARDS) as $shard) {
            $config = writeConfig($shard);
            $tests = array_count_values(listTests($config));
            unlink($config);
            printf("%-20s %5d tests\n", $shard, array_sum($tests));
            $sum += array_sum($tests);
            foreach ($tests as $test => $count) {
                if (isset($owner[$test])) {
                    fail("{$test} is in shard {$owner[$test]} and in shard {$shard}");
                }
                if (($full[$test] ?? 0) !== $count) {
                    fail("{$test}: listed {$count}x in shard {$shard}, ".($full[$test] ?? 0).'x in the full suite');
                }
                $owner[$test] = $shard;
            }
        }
        $missing = array_diff_key($full, $owner);
        if ($missing) {
            fail("tests of the full suite in no shard:\n  ".implode("\n  ", array_slice(array_keys($missing), 0, 50)));
        }
        printf("%-20s %5d tests = full suite (%d), each in exactly one shard\n", 'total', $sum, array_sum($full));
        break;

    default:
        fail('usage: php .github/ci/phpunit-shards.php check|matrix|config <shard>|verify');
}
