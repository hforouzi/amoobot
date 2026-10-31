<?php

declare(strict_types=1);

namespace App\Tests\Shared;

use App\Provisioning\Infrastructure\Sanaei3xui\Modern\Http\SecretRedactor;
use App\Shared\Infrastructure\RedactingLogger;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Log\Logger;

/**
 * M2e E4 (gaps the M2d review found): the Telegram id in a raw update's JSON, in URL-encoded text, in a trace with
 * arguments, and in a {placeholder} under a key that names it; and the numbers that must stay (order, payment and
 * service ids, amounts, ports, a generic "id").
 */
final class TelegramIdLogFormsTest extends TestCase
{
    private const ID = '512345678';

    /** @return iterable<string, array{string, string}> */
    public static function masked(): iterable
    {
        yield 'update "chat":{"id"}' => ['{"message":{"message_id":7,"chat":{"id":512345678,"type":"private"}}}', '{"message":{"message_id":7,"chat":{"id":[redacted],"type":"private"}}}'];
        yield 'update "from":{"id"} not first' => ['{"from":{"is_bot":false,"id":512345678,"first_name":"A"}}', '{"from":{"is_bot":false,"id":[redacted],"first_name":"A"}}'];
        yield 'callback "user", "sender_chat"' => ['{"user":{"id":512345678},"sender_chat":{"id":-100512345678}}', '{"user":{"id":[redacted]},"sender_chat":{"id":[redacted]}}'];
        yield 'url-encoded =' => ['body=chat_id%3D512345678%26text%3Dhi', 'body=chat_id%3D[redacted]%26text%3Dhi'];
        yield 'url-encoded lower case' => ['chat_id%3d512345678', 'chat_id%3d[redacted]'];
        yield 'url-encoded after &' => ['q=1%26chat_id%3D512345678', 'q=1%26chat_id%3D[redacted]'];
        yield 'url-encoded JSON' => ['%7B%22chat_id%22%3A512345678%7D', '%7B%22chat_id%22%3A[redacted]%7D'];
        yield 'url-encoded JSON string' => ['%7B%22chat_id%22%3A%22512345678%22%7D', '%7B%22chat_id%22%3A%22[redacted]%22%7D'];
        yield 'form spaces' => ['chat_id+%3D+512345678', 'chat_id+%3D+[redacted]'];
        yield 'email' => ['client tg_512345678_order_42 added', 'client tg_[redacted]_order_42 added'];
        yield 'email cut short' => ["provision('tg_512345678_or...')", "provision('tg_[redacted]_or...')"];
        yield 'trace frame string argument' => ["#0 /app/src/Bot/Infrastructure/TelegramApiClient.php(70): App\\Bot\\Infrastructure\\TelegramApiClient->sendMessageStrict('512345678', 'hi', NULL)", "#0 /app/src/Bot/Infrastructure/TelegramApiClient.php(70): App\\Bot\\Infrastructure\\TelegramApiClient->sendMessageStrict('[redacted]', 'hi', NULL)"];
        yield 'M2d forms still' => ['chat_id="512345678" actor_id=512345678 {"telegram_id":512345678}', 'chat_id="[redacted]" actor_id=[redacted] {"telegram_id":[redacted]}'];
    }

    #[DataProvider('masked')]
    public function testTheTelegramIdIsMasked(string $text, string $expected): void
    {
        self::assertSame($expected, SecretRedactor::forLog($text));
    }

    /** @return iterable<string, array{string}> */
    public static function untouched(): iterable
    {
        yield 'order, payment, service ids' => ['order_id=512345678 payment_id=987654321 service_id=555555'];
        yield 'amounts and ports' => ['amount=1500000000 toman port=2053 traffic_limit_gb=50 expiryTime=1798675200000'];
        yield 'generic id, inbound id' => ['{"id":512345678,"inbound_id":45678,"order":{"id":512345678}}'];
        yield 'encoded order id' => ['%22order_id%22%3A512345678 payment_id%3D512345678'];
        yield 'other words' => ['mychat_id=512345678 chatter=12345 tg_name tg_1234_order'];
        yield 'trace frame int arguments' => ["#1 /app/src/X.php(9): App\\Repo->find(512345678, 'abc')"];
        yield 'quoted digits outside a trace' => ["code '512345678' accepted"];
        yield 'a short chat id' => ['chat_id=123'];
    }

    #[DataProvider('untouched')]
    public function testUnrelatedNumbersStay(string $text): void
    {
        self::assertSame($text, SecretRedactor::forLog($text));
    }

    public function testATraceWithArgumentsKeepsNoTelegramId(): void
    {
        $ignore = ini_get('zend.exception_ignore_args');
        ini_set('zend.exception_ignore_args', '0');
        try {
            $e = self::caught(self::ID, 'tg_'.self::ID.'_order_42', 77);
        } finally {
            ini_set('zend.exception_ignore_args', (string) $ignore);
        }
        $trace = $e->getTraceAsString();
        self::assertStringContainsString(self::ID, $trace, 'the trace carries the arguments');

        $redacted = SecretRedactor::forLog($trace);

        self::assertStringNotContainsString(self::ID, $redacted);
        self::assertStringContainsString("refuse('[redacted]', 'tg_[redacted]_or...', 77)", $redacted, 'the int argument stays');
    }

    public function testPlaceholdersFollowTheKeyRule(): void
    {
        $inner = new Logger('debug', 'php://memory');
        $stringable = new class(self::ID) implements \Stringable {
            public function __construct(private readonly string $id)
            {
            }

            public function __toString(): string
            {
                return 'tg_'.$this->id.'_order_9';
            }
        };

        (new RedactingLogger($inner))->warning(
            'owner {owner_telegram_id} chat {customer_chat} actor {actorId} tg {tgId} from {from} client {client} order {order_id} payment {payment_id} amount {amount} port {port} count {chat_count}',
            ['owner_telegram_id' => (int) self::ID, 'customer_chat' => self::ID, 'actorId' => (int) self::ID, 'tgId' => (int) self::ID, 'from' => (int) self::ID, 'client' => $stringable,
                'order_id' => (int) self::ID, 'payment_id' => 98765, 'amount' => 1500000000, 'port' => 2053, 'chat_count' => 12],
        );

        $handle = (new \ReflectionProperty($inner, 'handle'))->getValue($inner);
        rewind($handle);
        self::assertSame(
            '[warning] owner [redacted] chat [redacted] actor [redacted] tg [redacted] from [redacted] client tg_[redacted]_order_9 order 512345678 payment 98765 amount 1500000000 port 2053 count 12',
            trim((string) preg_replace('/^\S+ /', '', (string) stream_get_contents($handle))),
        );
    }

    public function testTheKeyRule(): void
    {
        foreach (['chat', 'chat_id', 'owner_chat', 'telegram', 'customer_telegram_id', 'actor', 'actor_id', 'tg_id', 'tgId', 'from', 'from_id', 'fromId'] as $key) {
            self::assertTrue(RedactingLogger::isTelegramIdKey($key), $key);
        }
        foreach (['order_id', 'payment_id', 'service_id', 'user_id', 'amount', 'port', 'id', 'inbound_id', 'tg', 'fromDate', 'source'] as $key) {
            self::assertFalse(RedactingLogger::isTelegramIdKey($key), $key);
        }
    }

    private static function caught(string $chatId, string $email, int $orderId): \RuntimeException
    {
        try {
            self::refuse($chatId, $email, $orderId);
        } catch (\RuntimeException $e) {
            return $e;
        }
    }

    private static function refuse(string $chatId, string $email, int $orderId): never
    {
        throw new \RuntimeException('panel refused');
    }
}
