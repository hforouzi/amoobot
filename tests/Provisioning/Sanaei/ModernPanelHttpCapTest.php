<?php

declare(strict_types=1);

namespace App\Tests\Provisioning\Sanaei;

use App\Entity\VpnPanel;
use App\Provisioning\Infrastructure\PanelCredentials;
use App\Provisioning\Infrastructure\PanelHttpClientFactory;
use App\Provisioning\Infrastructure\Sanaei3xui\Modern\Http\ModernPanelHttpClient;
use App\Provisioning\Infrastructure\Sanaei3xui\Modern\Http\PanelApiException;
use App\Provisioning\Infrastructure\Sanaei3xui\Modern\Http\PanelFailure;
use App\Provisioning\Infrastructure\Sanaei3xui\Modern\ModernClientsApi;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpClient\CurlHttpClient;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * The 1 MiB cap on panel link answers with the real curl transport against a local server (`php -S`): a tiny gzip body
 * that unpacks to 200 MB, and 200 MB of plain text, are both refused without retry and without ever being held in
 * memory; the bot asks for uncompressed answers.
 */
final class ModernPanelHttpCapTest extends KernelTestCase
{
    private const MEMORY_BUDGET = 48 * 1024 * 1024;

    private static ?Process $server = null;
    private static string $dir = '';
    private static int $port = 0;

    public static function setUpBeforeClass(): void
    {
        if (!function_exists('curl_init')) {
            self::markTestSkipped('ext-curl is required.');
        }
        self::$dir = sys_get_temp_dir().'/amoobot-cap-'.bin2hex(random_bytes(4));
        mkdir(self::$dir);
        // 200 MB of "A" as gzip, built in 1 MB steps (never 200 MB in memory).
        $gzip = fopen(self::$dir.'/answer.gz', 'w');
        $deflate = deflate_init(ZLIB_ENCODING_GZIP, ['level' => 9]);
        $block = str_repeat('A', 1 << 20);
        fwrite($gzip, (string) deflate_add($deflate, '{"success":true,"msg":"","obj":["', ZLIB_NO_FLUSH));
        for ($i = 0; $i < 200; ++$i) {
            fwrite($gzip, (string) deflate_add($deflate, $block, ZLIB_NO_FLUSH));
        }
        fwrite($gzip, (string) deflate_add($deflate, '"]}', ZLIB_FINISH));
        fclose($gzip);
        self::assertLessThan(ModernClientsApi::LINKS_MAX_BYTES, (int) filesize(self::$dir.'/answer.gz'), 'the compressed answer itself is under the cap');

        $probe = stream_socket_server('tcp://127.0.0.1:0');
        self::$port = (int) substr((string) strrchr((string) stream_socket_get_name($probe, false), ':'), 1);
        fclose($probe);
        self::$server = new Process(
            [(new PhpExecutableFinder())->find(false) ?: PHP_BINARY, '-d', 'memory_limit=64M', '-S', '127.0.0.1:'.self::$port, dirname(__DIR__, 2).'/Support/Fake3xui/oversized-answer-router.php'],
            null,
            ['OVERSIZED_GZIP_FILE' => self::$dir.'/answer.gz', 'OVERSIZED_RECORD_FILE' => self::$dir.'/accept-encoding.txt'],
        );
        self::$server->start();
        for ($i = 0; $i < 100; ++$i) {
            $socket = @fsockopen('127.0.0.1', self::$port, $errno, $errstr, 0.1);
            if (false !== $socket) {
                fclose($socket);

                return;
            }
            usleep(50_000);
        }
        self::fail('the local test server did not start: '.self::$server->getErrorOutput());
    }

    public static function tearDownAfterClass(): void
    {
        self::$server?->stop(1);
        foreach (glob(self::$dir.'/*') ?: [] as $file) {
            unlink($file);
        }
        if ('' !== self::$dir && is_dir(self::$dir)) {
            rmdir(self::$dir);
        }
    }

    public function testAGzipAnswerThatUnpacksTo200MbIsRefusedWithoutUsingTheMemory(): void
    {
        $this->assertRefused('gzip');
        self::assertSame(['identity'], array_values(array_unique(file(self::$dir.'/accept-encoding.txt', FILE_IGNORE_NEW_LINES) ?: [])), 'the bot asks for uncompressed answers');
    }

    public function testA200MbPlainAnswerIsAbortedAtTheCap(): void
    {
        $this->assertRefused('plain');
    }

    private function assertRefused(string $kind): void
    {
        @unlink(self::$dir.'/accept-encoding.txt');
        $panel = (new VpnPanel())->setType('sanaei_3xui')->setTitle('local')->setBaseUrl('http://127.0.0.1:'.self::$port.'/'.$kind)->setPlainApiToken('cap-test-token');
        $client = new ModernPanelHttpClient(new CurlHttpClient(), new PanelHttpClientFactory(), self::getContainer()->get(PanelCredentials::class), sleeper: static function (int $ms): void {
            self::fail('an oversized answer must not be retried');
        });

        gc_collect_cycles();
        $baseline = memory_get_usage();
        memory_reset_peak_usage();
        try {
            $client->get($panel, 'panel/api/clients/subLinks/capsub0123456789', [], ModernClientsApi::LINKS_MAX_BYTES, 'panel/api/clients/subLinks/{subId}');
            self::fail('an oversized answer was accepted');
        } catch (PanelApiException $e) {
            self::assertContains($e->failure, [PanelFailure::UNEXPECTED_RESPONSE], $e->getMessage());
            self::assertStringNotContainsString('capsub0123456789', $e->getMessage());
        }
        self::assertLessThan(self::MEMORY_BUDGET, memory_get_peak_usage() - $baseline, 'the answer was never held whole in memory');
        self::assertCount(1, file(self::$dir.'/accept-encoding.txt') ?: [], 'one request, no retry');
    }
}
