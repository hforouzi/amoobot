<?php

declare(strict_types=1);

namespace App\Tests\Provisioning;

use App\Entity\ServiceNotificationLog;
use App\Tests\Support\CapturesErrorLog;
use App\Tests\Support\ConsoleOutputAssertions;
use App\Tests\Support\IntegrationTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * The notifications job (app:automation:run --only=notifications) and a recipient the bot cannot reach: a blocked bot
 * (403) or a chat Telegram does not know (400 chat not found) records the notice as undeliverable, so it is not sent
 * again and the job does not fail. Any other refusal (429, a 400 about the text) or a transport error stays a failure:
 * nothing recorded, sent again on the next run.
 */
final class NotificationUnreachableRecipientTest extends IntegrationTestCase
{
    use CapturesErrorLog;
    use ConsoleOutputAssertions;

    private const CUSTOMER = '7712345601';

    /** @return iterable<string, array{int, string, string}> */
    public static function unreachable(): iterable
    {
        yield '403 bot blocked' => [403, 'Forbidden: bot was blocked by the user', 'blocked'];
        yield '400 chat not found' => [400, 'Bad Request: chat not found', 'chat_not_found'];
    }

    #[DataProvider('unreachable')]
    public function testAnUnreachableRecipientIsRecordedAndNotRetried(int $code, string $description, string $reason): void
    {
        $serviceId = $this->serviceAt82PercentTraffic();
        $this->telegramAnswers($code, $description);

        $log = $this->captureErrorLog(function () use (&$tester): void {
            $tester = $this->runJob();
        });

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        self::assertConsoleContains('failed: 0', $tester->getDisplay());
        self::assertConsoleContains('undeliverable: 1', $tester->getDisplay());
        $rows = $this->logRows($serviceId);
        self::assertCount(1, $rows);
        self::assertSame('traffic_80', $rows[0]->getKeyName());
        self::assertSame($reason, $rows[0]->getPayload()['undeliverable'] ?? null);
        self::assertStringContainsString(sprintf('undeliverable service_id=%d type=traffic_warning key=traffic_80 reason=%s', $serviceId, $reason), $log);
        self::assertStringNotContainsString(self::CUSTOMER, $log, 'no Telegram id in the log');
        self::assertCount(1, $this->http->requests('/sendMessage'));

        $this->http->reset();
        $this->telegramAnswers($code, $description);
        $again = $this->runJob();

        self::assertSame(Command::SUCCESS, $again->getStatusCode());
        self::assertCount(0, $this->http->requests('/sendMessage'), 'not sent again');
        self::assertCount(1, $this->logRows($serviceId));
    }

    /** @return iterable<string, array{int, string}> */
    public static function retryable(): iterable
    {
        yield '429 flood limit' => [429, 'Too Many Requests: retry after 5'];
        yield '400 bad entities' => [400, "Bad Request: can't parse entities: Can't find end of the entity starting at byte offset 3"];
    }

    #[DataProvider('retryable')]
    public function testAnyOtherRefusalFailsAndIsRetried(int $code, string $description): void
    {
        $serviceId = $this->serviceAt82PercentTraffic();
        $this->telegramAnswers($code, $description);

        $tester = $this->runJob();

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertConsoleContains('failed: 1', $tester->getDisplay());
        self::assertConsoleContains('undeliverable: 0', $tester->getDisplay());
        self::assertCount(0, $this->logRows($serviceId));

        $this->http->reset();
        $again = $this->runJob();

        self::assertSame(Command::SUCCESS, $again->getStatusCode());
        self::assertCount(1, $this->http->requests('/sendMessage'), 'sent again on the next run');
        self::assertCount(1, $this->logRows($serviceId));
        self::assertArrayNotHasKey('undeliverable', $this->logRows($serviceId)[0]->getPayload() ?? []);
    }

    public function testATransportErrorFailsAndIsRetried(): void
    {
        $serviceId = $this->serviceAt82PercentTraffic();
        $this->http->on('/sendMessage', static fn (): ResponseInterface => new MockResponse([new TransportException('Connection reset by peer')]));

        $tester = $this->runJob();

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertConsoleContains('failed: 1', $tester->getDisplay());
        self::assertCount(0, $this->logRows($serviceId));
    }

    public function testTheStandaloneCommandCountsItToo(): void
    {
        $this->serviceAt82PercentTraffic();
        $this->telegramAnswers(403, 'Forbidden: user is deactivated');

        $tester = new CommandTester((new Application(self::$kernel))->find('app:service:send-notifications'));
        $tester->execute(['--type' => 'traffic']);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        self::assertConsoleContains('undeliverable: 1', $tester->getDisplay());
        self::assertConsoleContains('failed: 0', $tester->getDisplay());
    }

    /** An active service at 82% of its traffic, expiring in 20 days: exactly one notice due (traffic_80, thresholds 80,95,100 in .env). */
    private function serviceAt82PercentTraffic(): int
    {
        $service = $this->createActiveService($this->createUser(self::CUSTOMER))->setTrafficUsedGb(41);
        $this->em->flush();
        $this->http->reset();

        return (int) $service->getId();
    }

    private function telegramAnswers(int $code, string $description): void
    {
        $this->http->onJson('/sendMessage', ['ok' => false, 'error_code' => $code, 'description' => $description], $code);
    }

    private function runJob(): CommandTester
    {
        $this->em->clear();
        $tester = new CommandTester((new Application(self::$kernel))->find('app:automation:run'));
        $tester->execute(['--only' => 'notifications']);
        $this->em->clear();

        return $tester;
    }

    /** @return list<ServiceNotificationLog> */
    private function logRows(int $serviceId): array
    {
        $this->em->clear();

        return $this->em->getRepository(ServiceNotificationLog::class)->findBy(['service' => $serviceId]);
    }
}
