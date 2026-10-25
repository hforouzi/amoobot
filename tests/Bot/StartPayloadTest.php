<?php

declare(strict_types=1);

namespace App\Tests\Bot;

use App\Bot\Application\DeepLink\StartPayload;
use App\Bot\Application\DeepLink\StartPayloadRouter;
use App\Entity\TelegramAccount;
use App\Reseller\Domain\ClaimToken;
use App\Tests\Support\RecordingStartPayloadHandler;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The shared deep-link payload and router (claim_ now, ref_ later): payloads are parsed exactly (no trimming, "$" is the
 * very end), a prefix has one handler, an unknown prefix or a switched-off handler is not routed, and the Mini App
 * target can only be a section of the app.
 */
final class StartPayloadTest extends TestCase
{
    /**
     * @return iterable<string, array{string, ?array{string, string}}>
     */
    public static function startTexts(): iterable
    {
        $token = str_repeat('aB3_-', 8).'xyz';
        yield 'claim link' => ['/start claim_'.$token, ['claim', $token]];
        yield 'with the bot name' => ['/start@amoo_test_bot claim_'.$token, ['claim', $token]];
        yield 'later: referral' => ['/start ref_ABC123', ['ref', 'ABC123']];
        yield 'plain start' => ['/start', null];
        yield 'trailing newline' => ["/start claim_$token\n", null];
        yield 'trailing space' => ["/start claim_$token ", null];
        yield 'two spaces' => ["/start  claim_$token", null];
        yield 'tab' => ["/start\tclaim_$token", null];
        yield 'two words' => ["/start claim_$token x", null];
        yield 'upper-case prefix' => ['/start Claim_abc', null];
        yield 'no prefix' => ['/start _abc', null];
        yield 'no value' => ['/start claim_', null];
        yield 'other characters' => ['/start claim_ab+c', null];
        yield 'non-ASCII digits' => ['/start claim_۱۲۳', null];
        yield 'over 64 characters' => ['/start claim_'.str_repeat('a', 59), null];
        yield 'not /start' => ['/started claim_abc', null];
    }

    /**
     * @param array{string, string}|null $expected
     */
    #[DataProvider('startTexts')]
    public function testStartCommandsAreParsedExactly(string $text, ?array $expected): void
    {
        $payload = StartPayload::fromStartCommand($text);

        self::assertSame($expected, null === $payload ? null : [$payload->prefix, $payload->value]);
    }

    public function testATokenPayloadFitsTelegramsLimits(): void
    {
        $payload = ClaimToken::payload(ClaimToken::generate());

        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]{1,64}$/D', $payload);
        self::assertNotNull(StartPayload::parse($payload));
        self::assertNull(StartPayload::parse($payload."\n"));
    }

    public function testTheTokenIsMaskedInAnythingLogged(): void
    {
        $token = ClaimToken::generate();

        self::assertSame('/start claim_[redacted]', ClaimToken::redact('/start claim_'.$token));
        self::assertSame('https://t.me/b?start=claim_[redacted] and claim_[redacted]', ClaimToken::redact('https://t.me/b?start=claim_'.$token.' and claim_'.$token));
        self::assertSame('trial_claims are fine', ClaimToken::redact('trial_claims are fine'));
        self::assertSame(['text' => '/start claim_[redacted]', 'n' => 5, 'x' => ['claim_[redacted]' => 'claim_[redacted]']], \App\Bot\Application\BotMessageLogger::redact(['text' => '/start claim_'.$token, 'n' => 5, 'x' => ['claim_'.$token => 'claim_'.$token]]));
    }

    public function testTheRouterPicksTheHandlerByPrefixAndIgnoresTheRest(): void
    {
        $claim = $this->handler('claim', true, '/app/view/claim/1/x');
        $off = $this->handler('ref', false, '/app/view/home');
        $router = new StartPayloadRouter([$claim, $off], new NullLogger());
        $account = new TelegramAccount();

        self::assertTrue($router->routeBot($account, '1', StartPayload::parse('claim_abc')));
        self::assertSame(['abc'], $claim->seen);
        self::assertFalse($router->routeBot($account, '1', StartPayload::parse('ref_abc')), 'switched off');
        self::assertFalse($router->routeBot($account, '1', StartPayload::parse('other_abc')), 'no handler');
        self::assertFalse($router->routeBot($account, '1', null));
        self::assertSame('/app/view/claim/1/x', $router->routeMiniApp($account, 'claim_abc'));
        self::assertNull($router->routeMiniApp($account, 'claim_abc '));
        self::assertNull($router->routeMiniApp($account, null));

        $evil = new StartPayloadRouter([$this->handler('claim', true, 'https://evil.example/app/view/x')], new NullLogger());
        self::assertNull($evil->routeMiniApp($account, 'claim_abc'), 'only a section of the app');
    }

    public function testAPrefixHasOneHandler(): void
    {
        $this->expectException(\LogicException::class);
        new StartPayloadRouter([$this->handler('claim', true, null), $this->handler('claim', true, null)], new NullLogger());
    }

    private function handler(string $prefix, bool $enabled, ?string $target): RecordingStartPayloadHandler
    {
        return new RecordingStartPayloadHandler($prefix, $enabled, $target);
    }
}
