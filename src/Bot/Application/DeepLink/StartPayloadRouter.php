<?php

declare(strict_types=1);

namespace App\Bot\Application\DeepLink;

use App\Entity\TelegramAccount;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * The one router of deep-link payloads (docs/design/reseller-and-claim.md, 3.2 / 6.5), for the bot's "/start <payload>"
 * and the Mini App's start_param alike. Handlers register by prefix (StartPayloadHandler); referrals add "ref" the same
 * way. A payload that is malformed, has no handler, or whose handler is switched off is not routed: the caller then does
 * exactly what it does without one (the usual /start, the usual home). The value of a payload whose prefix has a
 * handler is never logged; an unknown payload is (logUnknownBotPayload), to count returns through a shared link.
 */
class StartPayloadRouter
{
    public const MINI_APP_TARGET = '#^/app/view/[A-Za-z0-9/_-]{1,200}(?:\?topic=[a-z_]{1,32})?$#D';

    /** @var array<string, StartPayloadHandler> */
    private array $handlers = [];

    /**
     * @param iterable<StartPayloadHandler> $handlers
     */
    public function __construct(
        #[AutowireIterator('app.start_payload_handler')]
        iterable $handlers,
        private readonly LoggerInterface $logger,
    ) {
        foreach ($handlers as $handler) {
            $prefix = $handler->prefix();
            if (1 !== preg_match('/^[a-z]{1,16}$/D', $prefix) || isset($this->handlers[$prefix])) {
                throw new \LogicException(sprintf('Invalid or duplicate start payload prefix "%s".', $prefix));
            }
            $this->handlers[$prefix] = $handler;
        }
    }

    /**
     * @return bool true when a handler took the payload (the caller must not also send the usual /start answer)
     */
    public function routeBot(TelegramAccount $account, string $chatId, ?StartPayload $payload): bool
    {
        $handler = $this->handlerFor($payload);
        if (null === $handler || null === $payload) {
            return false;
        }
        $handler->handleBot($account, $chatId, $payload->value);

        return true;
    }

    /**
     * One info line for a bot "/start <payload>" that no handler took (e.g. "comeback" from a link shared to bring old
     * users back), so returns can be counted. The payload is logged only when it fits Telegram's start alphabet and no
     * handler owns its prefix: the value of a claim_ (or any other handled) payload may be a secret and is never logged,
     * whether or not it was taken. Anything else is logged as "<invalid>".
     */
    public function logUnknownBotPayload(string $text): void
    {
        $raw = StartPayload::rawFromStartCommand($text);
        if (null === $raw) {
            return;
        }
        $payload = StartPayload::parse($raw);
        if (null !== $payload && isset($this->handlers[$payload->prefix])) {
            return;
        }
        $this->logger->info('start_payload_unknown', ['payload' => 1 === preg_match(StartPayload::TELEGRAM_PATTERN, $raw) ? $raw : '<invalid>']);
    }

    /** The first section of a Mini App opened with this start parameter, or null for the usual home. */
    public function routeMiniApp(TelegramAccount $account, ?string $startParam): ?string
    {
        $payload = null === $startParam ? null : StartPayload::parse($startParam);
        $handler = $this->handlerFor($payload);
        if (null === $handler || null === $payload) {
            return null;
        }
        $target = $handler->miniAppTarget($account, $payload->value);

        // Only a section of the app itself, so a handler can never send the app elsewhere; the one query allowed is a
        // guide topic (?topic=<slug>, the same rule app.js applies).
        return null !== $target && 1 === preg_match(self::MINI_APP_TARGET, $target) ? $target : null;
    }

    private function handlerFor(?StartPayload $payload): ?StartPayloadHandler
    {
        if (null === $payload) {
            return null;
        }
        $handler = $this->handlers[$payload->prefix] ?? null;
        if (null === $handler || !$handler->enabled()) {
            $this->logger->info('start_payload_ignored', ['prefix' => $payload->prefix, 'reason' => null === $handler ? 'no_handler' : 'disabled']);

            return null;
        }
        if (!$handler->accepts($payload->value)) {
            $this->logger->info('start_payload_ignored', ['prefix' => $payload->prefix, 'reason' => 'not_accepted']);

            return null;
        }

        return $handler;
    }
}
