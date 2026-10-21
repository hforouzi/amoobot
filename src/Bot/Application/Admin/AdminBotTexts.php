<?php

declare(strict_types=1);

namespace App\Bot\Application\Admin;

/**
 * Default (Persian) texts and button labels of the bot admin area, merged into BotContentRegistry. Each can be changed
 * in the admin (bot message templates / button labels) like every other bot text.
 */
final class AdminBotTexts
{
    /**
     * @return array<string, array{title: string, body: string, category: string, variables?: list<string>}>
     */
    public static function messages(): array
    {
        $m = static fn (string $title, string $body, array $variables = []): array => ['title' => $title, 'body' => $body, 'category' => 'admin'] + ([] === $variables ? [] : ['variables' => $variables]);

        return [
        ];
    }

    /**
     * @return array<string, array{label: string, buttonType: string, category: string}>
     */
    public static function buttons(): array
    {
        $b = static fn (string $label): array => ['label' => $label, 'buttonType' => 'inline', 'category' => 'admin'];

        return [
            'button.admin.payments_attention' => $b('⚠️ پرداخت‌های نیازمند رسیدگی'),
        ];
    }
}
