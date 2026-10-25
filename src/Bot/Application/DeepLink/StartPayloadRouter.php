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
 * exactly what it does without one (the usual /start, the usual home). The payload's value is never logged.
 */
class StartPayloadRouter
{
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

    /** The first section of a Mini App opened with this start parameter, or null for the usual home. */
    public function routeMiniApp(TelegramAccount $account, ?string $startParam): ?string
    {
        $payload = null === $startParam ? null : StartPayload::parse($startParam);
        $handler = $this->handlerFor($payload);
        if (null === $handler || null === $payload) {
            return null;
        }
        $target = $handler->miniAppTarget($account, $payload->value);

        // Only a section of the app itself, so a handler can never send the app elsewhere.
        return null !== $target && 1 === preg_match('#^/app/view/[A-Za-z0-9/_-]{1,200}$#D', $target) ? $target : null;
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

        return $handler;
    }
}
