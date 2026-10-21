<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Bot\Application\BotTextResolver;
use App\Bot\Application\TelegramUpdateHandler;
use App\Entity\AdminUser;

/**
 * Drives the bot like Telegram does (updates into TelegramUpdateHandler) and reads what it sent back through the fake
 * Telegram API (MockHttpResponder records every Bot API call). For IntegrationTestCase subclasses.
 */
trait BotAdminTestTrait
{
    protected const ENV_ADMIN = '999000'; // TELEGRAM_ADMIN_CHAT_ID in .env.test

    protected function pressButton(string $telegramId, string $data): void
    {
        self::assertLessThanOrEqual(64, strlen($data), 'callback data over 64 bytes: '.$data);
        self::getContainer()->get(TelegramUpdateHandler::class)->handle([
            'update_id' => random_int(1, PHP_INT_MAX),
            'callback_query' => [
                'id' => 'cb'.random_int(1, PHP_INT_MAX),
                'from' => ['id' => (int) $telegramId, 'is_bot' => false, 'first_name' => 'T'.$telegramId],
                'message' => ['message_id' => 1, 'chat' => ['id' => (int) $telegramId, 'type' => 'private']],
                'data' => $data,
            ],
        ]);
    }

    protected function sendText(string $telegramId, string $text): void
    {
        self::getContainer()->get(TelegramUpdateHandler::class)->handle([
            'update_id' => random_int(1, PHP_INT_MAX),
            'message' => [
                'message_id' => random_int(1, 100000),
                'from' => ['id' => (int) $telegramId, 'is_bot' => false, 'first_name' => 'T'.$telegramId],
                'chat' => ['id' => (int) $telegramId, 'type' => 'private'],
                'date' => time(),
                'text' => $text,
            ],
        ]);
    }

    /** Refused with the existing "unauthorized" answer. */
    protected function refused(): bool
    {
        foreach ($this->http->requests('/answerCallbackQuery') as $request) {
            if (in_array((string) ($this->requestBody($request)['text'] ?? ''), [$this->botText('admin.unauthorized'), 'Unauthorized'], true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Callback data of every inline button sent since the last reset, in order.
     *
     * @return list<string>
     */
    protected function sentCallbacks(): array
    {
        $callbacks = [];
        foreach ($this->sentKeyboards() as $keyboard) {
            foreach ($keyboard as $row) {
                foreach ($row as $button) {
                    if (isset($button['callback_data'])) {
                        $callbacks[] = (string) $button['callback_data'];
                    }
                }
            }
        }

        return $callbacks;
    }

    /**
     * Inline keyboards sent since the last reset (each a list of rows of buttons). Also checks Telegram's limits.
     *
     * @return list<list<list<array<string, mixed>>>>
     */
    protected function sentKeyboards(): array
    {
        $keyboards = [];
        foreach ([...$this->http->requests('/sendMessage'), ...$this->http->requests('/sendPhoto'), ...$this->http->requests('/editMessageText')] as $request) {
            $markup = $this->requestBody($request)['reply_markup'] ?? null;
            if (is_string($markup)) {
                $markup = json_decode($markup, true);
            }
            if (is_array($markup) && isset($markup['inline_keyboard'])) {
                foreach ($markup['inline_keyboard'] as $row) {
                    foreach ($row as $button) {
                        if (isset($button['callback_data'])) {
                            self::assertLessThanOrEqual(64, strlen((string) $button['callback_data']), 'callback data over 64 bytes: '.$button['callback_data']);
                        }
                        self::assertArrayNotHasKey('url', $button, 'the admin area sends no links');
                    }
                }
                $keyboards[] = $markup['inline_keyboard'];
            }
        }

        return $keyboards;
    }

    /**
     * Text of every message sent since the last reset, popups included.
     *
     * @return list<string>
     */
    protected function sentTexts(): array
    {
        $texts = $this->http->telegramTexts();
        foreach ([...$this->http->requests('/answerCallbackQuery'), ...$this->http->requests('/sendPhoto')] as $request) {
            $body = $this->requestBody($request);
            $texts[] = (string) ($body['text'] ?? $body['caption'] ?? '');
        }

        return $texts;
    }

    protected function sentTextContains(string $needle): bool
    {
        foreach ($this->sentTexts() as $text) {
            if (str_contains($text, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array{options: array<string, mixed>} $request
     *
     * @return array<string, mixed>
     */
    protected function requestBody(array $request): array
    {
        $body = $request['options']['body'] ?? $request['options']['json'] ?? [];
        if (is_string($body)) {
            $decoded = json_decode($body, true);
            if (!is_array($decoded)) {
                parse_str($body, $decoded);
            }
            $body = $decoded;
        }

        return is_array($body) ? $body : [];
    }

    protected function botText(string $key, array $variables = []): string
    {
        return self::getContainer()->get(BotTextResolver::class)->message($key, $variables);
    }

    protected function botButton(string $key): string
    {
        return self::getContainer()->get(BotTextResolver::class)->button($key);
    }

    protected function linkedAdmin(string $email, string $role, ?string $telegramUserId, bool $active = true): AdminUser
    {
        $admin = (new AdminUser())->setEmail($email)->setPassword('unused')->setRoles([$role])->setIsActive($active)->setTelegramUserId($telegramUserId);
        $this->em->persist($admin);
        $this->em->flush();

        return $admin;
    }
}
