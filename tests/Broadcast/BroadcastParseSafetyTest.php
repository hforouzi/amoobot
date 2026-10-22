<?php

declare(strict_types=1);

namespace App\Tests\Broadcast;

use App\Broadcast\BroadcastAdministration;
use App\Broadcast\BroadcastFormatException;
use App\Broadcast\BroadcastSender;
use App\Entity\BroadcastDelivery;
use App\Entity\BroadcastMessage;
use App\Tests\Support\BotAdminTestTrait;
use App\Tests\Support\IntegrationTestCase;
use App\Tests\Support\MiniAppAdminFixtures;
use App\Tests\Support\MiniAppTestTrait;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * The shared broadcast service (web, bot and Mini App) against texts Telegram cannot parse: the format is checked
 * before recipients are prepared, a broadcast whose first deliveries all fail with "can't parse entities" stops
 * itself (the admins told once, nobody else tried), retrying such failures is refused, other failures are left as
 * they were, and a finished broadcast can be duplicated as a new draft.
 */
final class BroadcastParseSafetyTest extends IntegrationTestCase
{
    use BotAdminTestTrait;
    use MiniAppAdminFixtures;
    use MiniAppTestTrait;

    private const PARSE_ERROR = '{"ok":false,"error_code":400,"description":"Bad Request: can\'t parse entities: Character \'.\' is reserved and must be escaped with the preceding \'\\\\\'"}';
    private const CUSTOMERS = ['840601', '840602', '840603', '840604', '840605'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->clean();
        $this->setUpAdminCast();
        // With the fixture's buyer, six customers with an active service.
        foreach (self::CUSTOMERS as $telegramId) {
            $this->createActiveService($this->createUser($telegramId), $this->service->getInbound());
        }
        $this->em->flush();
    }

    protected function tearDown(): void
    {
        $this->clean();
        parent::tearDown();
    }

    public function testThePlainFormatIsTheDefault(): void
    {
        self::assertNull((new BroadcastMessage())->getParseMode());
    }

    public function testAnUnparsableTextIsNotPrepared(): void
    {
        $broadcast = $this->broadcast('نسخه 2.5 آماده است', 'MarkdownV2');

        self::assertSame('broadcast.format.markdown.reserved', $this->administration()->formatProblem($broadcast)?->message);
        try {
            $this->administration()->prepare($broadcast);
            self::fail('prepared an unparsable text');
        } catch (BroadcastFormatException $e) {
            self::assertSame('broadcast.format.markdown.reserved', $e->problem->message);
        }
        self::assertSame(0, $this->deliveries($broadcast));

        $broadcast->setBody('نسخه 2\.5 آماده است');
        self::assertNull($this->administration()->formatProblem($broadcast));
        self::assertSame(6, $this->administration()->prepare($broadcast)?->prepared);
    }

    public function testRepeatedParseErrorsStopTheBroadcastAndTellTheAdminsOnce(): void
    {
        $this->http->on('/sendMessage', self::answer(static fn (string $chat): bool => in_array($chat, [self::BUYER, ...self::CUSTOMERS], true)));
        // Sent as it was saved before this check existed (or by a tool that skipped it).
        $broadcast = $this->broadcast('Version 2.5 is out', 'MarkdownV2');
        $this->sender()->prepareDeliveries($broadcast);
        $broadcast->setStatus(BroadcastMessage::STATUS_SENDING);
        $this->em->flush();

        $this->sender()->sendPending($broadcast, BroadcastAdministration::BATCH);

        self::assertCount(BroadcastSender::FORMAT_ERROR_STOP_AFTER, $this->customerRequests(), 'stopped after the first three');
        $this->em->clear();
        $stopped = $this->em->find(BroadcastMessage::class, $broadcast->getId()) ?? throw new \LogicException();
        self::assertSame(BroadcastMessage::STATUS_STOPPED, $stopped->getStatus());
        self::assertSame([6, 0, 3], [$stopped->getTotalRecipients(), $stopped->getSentCount(), $stopped->getFailedCount()]);
        self::assertSame(3, $this->em->getRepository(BroadcastDelivery::class)->count(['broadcast' => $stopped->getId(), 'status' => BroadcastDelivery::STATUS_PENDING]), 'the rest is not tried');
        self::assertSame('format_error', $stopped->getMetadata()['stopReason'] ?? null);
        self::assertNotNull($stopped->getFinishedAt());

        $alerts = $this->adminAlerts();
        self::assertSame([self::SUPER, self::ADMIN], array_map('strval', array_keys($alerts)), 'the admins who can send broadcasts, not support');
        self::assertStringContainsString('#'.$stopped->getId(), $alerts[(int) self::ADMIN] ?? '');
        self::assertStringContainsString("Character '.' is reserved", $alerts[(int) self::ADMIN] ?? '');

        // Nothing goes on: not by the runner, not by a retry, and the admins are not told again.
        $this->http->reset();
        $this->http->on('/sendMessage', self::answer(static fn (string $chat): bool => in_array($chat, [self::BUYER, ...self::CUSTOMERS], true)));
        $this->sender()->runDue(50);
        self::assertTrue($this->administration()->retryBlocked($stopped));
        self::assertNull($this->administration()->retryFailed($stopped));
        self::assertCount(0, $this->http->requests('/sendMessage'));
    }

    public function testOtherFailuresDoNotStopTheBroadcast(): void
    {
        $blocked = array_slice(self::CUSTOMERS, 0, 4);
        $this->http->on('/sendMessage', static function (string $method, string $url, array $options) use ($blocked): MockResponse {
            $chat = (string) (json_decode((string) ($options['body'] ?? '{}'), true)['chat_id'] ?? '');

            return in_array($chat, $blocked, true)
                ? new MockResponse('{"ok":false,"error_code":403,"description":"Forbidden: bot was blocked by the user"}', ['http_code' => 403, 'response_headers' => ['content-type' => 'application/json']])
                : new MockResponse('{"ok":true,"result":{"message_id":7}}', ['response_headers' => ['content-type' => 'application/json']]);
        });
        $broadcast = $this->broadcast('Hello', null);
        $this->administration()->prepare($broadcast);
        $this->administration()->sendPrepared($broadcast);

        $this->em->clear();
        $done = $this->em->find(BroadcastMessage::class, $broadcast->getId()) ?? throw new \LogicException();
        self::assertSame([BroadcastMessage::STATUS_FAILED, 2, 4], [$done->getStatus(), $done->getSentCount(), $done->getFailedCount()]);
        self::assertSame([], $this->adminAlerts());
        self::assertFalse($this->administration()->retryBlocked($done), 'a blocked user may have unblocked the bot');
        self::assertNotNull($this->administration()->retryFailed($done));
    }

    public function testAFinishedBroadcastIsDuplicatedAsANewDraft(): void
    {
        $source = $this->broadcast('Version 2.5 is out', 'MarkdownV2', BroadcastMessage::TARGET_USERS_WITH_EXPIRING_SERVICE);
        $source->setDisableWebPagePreview(false)->setTargetUserIds([5, 6])->setMetadata(['expiringDays' => 7, 'stopReason' => 'format_error', 'formatError' => ['error' => 'x', 'fingerprint' => 'y']])
            ->setStatus(BroadcastMessage::STATUS_STOPPED)->setTotalRecipients(6)->setFailedCount(3)->setStartedAt(new \DateTimeImmutable())->setFinishedAt(new \DateTimeImmutable());
        $this->em->flush();

        $copy = $this->administration()->duplicate($source, $this->adminUser);

        self::assertNotNull($copy);
        self::assertNotSame($source->getId(), $copy->getId());
        self::assertSame(
            ['Version 2.5 is out', 'Version 2.5 is out', 'MarkdownV2', false, BroadcastMessage::TARGET_USERS_WITH_EXPIRING_SERVICE, [5, 6], ['expiringDays' => 7, 'duplicatedFrom' => $source->getId()]],
            [$copy->getTitle(), $copy->getBody(), $copy->getParseMode(), $copy->isDisableWebPagePreview(), $copy->getTargetType(), $copy->getTargetUserIds(), $copy->getMetadata()],
        );
        self::assertSame([BroadcastMessage::STATUS_DRAFT, 0, 0, 0, null, null, null], [$copy->getStatus(), $copy->getTotalRecipients(), $copy->getSentCount(), $copy->getFailedCount(), $copy->getStartedAt(), $copy->getFinishedAt(), $copy->getSendAt()]);
        self::assertSame($this->adminUser->getId(), $copy->getCreatedBy()?->getId());
        self::assertSame(0, $this->deliveries($copy));

        self::assertNull($this->administration()->duplicate($copy, null), 'a draft is edited, not duplicated');
    }

    /**
     * Telegram's answer: the parse error to the given chats, ok to everyone else.
     *
     * @param callable(string): bool $failing
     */
    private static function answer(callable $failing): callable
    {
        return static function (string $method, string $url, array $options) use ($failing): MockResponse {
            $chat = (string) (json_decode((string) ($options['body'] ?? '{}'), true)['chat_id'] ?? '');

            return $failing($chat)
                ? new MockResponse(self::PARSE_ERROR, ['http_code' => 400, 'response_headers' => ['content-type' => 'application/json']])
                : new MockResponse('{"ok":true,"result":{"message_id":7}}', ['response_headers' => ['content-type' => 'application/json']]);
        };
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function customerRequests(): array
    {
        return array_values(array_filter($this->http->requests('/sendMessage'), fn (array $r): bool => in_array((string) ($this->requestBody($r)['chat_id'] ?? ''), [self::BUYER, ...self::CUSTOMERS], true)));
    }

    /**
     * @return array<int|string, string> admin chat (numeric: an int key) => the text they got
     */
    private function adminAlerts(): array
    {
        $alerts = [];
        foreach ($this->http->requests('/sendMessage') as $request) {
            $body = $this->requestBody($request);
            $chat = (string) ($body['chat_id'] ?? '');
            if (!in_array($chat, [self::BUYER, ...self::CUSTOMERS], true)) {
                self::assertArrayNotHasKey($chat, $alerts, 'told once');
                $alerts[$chat] = (string) ($body['text'] ?? '');
            }
        }

        return $alerts;
    }

    private function broadcast(string $body, ?string $parseMode, string $target = BroadcastMessage::TARGET_USERS_WITH_ACTIVE_SERVICE): BroadcastMessage
    {
        $broadcast = (new BroadcastMessage())->setTitle($body)->setBody($body)->setParseMode($parseMode)->setTargetType($target);
        $this->em->persist($broadcast);
        $this->em->flush();

        return $broadcast;
    }

    private function deliveries(BroadcastMessage $broadcast): int
    {
        return $this->em->getRepository(BroadcastDelivery::class)->count(['broadcast' => $broadcast->getId()]);
    }

    private function administration(): BroadcastAdministration
    {
        return self::getContainer()->get(BroadcastAdministration::class);
    }

    private function sender(): BroadcastSender
    {
        return self::getContainer()->get(BroadcastSender::class);
    }

    private function clean(): void
    {
        $connection = $this->em->getConnection();
        $connection->executeStatement('DELETE FROM broadcast_delivery');
        $connection->executeStatement('DELETE FROM broadcast_message');
    }
}
