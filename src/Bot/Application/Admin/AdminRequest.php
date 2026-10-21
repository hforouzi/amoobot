<?php

declare(strict_types=1);

namespace App\Bot\Application\Admin;

use App\Security\AdminActor;

/**
 * One admin action in the bot: who, where, and the parsed callback "admin.<area>:<verb>:<arg>:<arg>".
 */
final class AdminRequest
{
    /**
     * @param list<string> $args
     */
    public function __construct(
        public readonly AdminActor $admin,
        public readonly string $chatId,
        public readonly ?string $callbackId,
        public readonly string $verb,
        public readonly array $args,
    ) {
    }

    public function intArg(int $index, int $default = 0): int
    {
        $value = $this->args[$index] ?? '';

        return 1 === preg_match('/^\d{1,10}$/', $value) ? (int) $value : $default;
    }

    public function arg(int $index, string $default = ''): string
    {
        return $this->args[$index] ?? $default;
    }

    /**
     * "admin.pl:pr:12" -> area "pl", verb "pr", args ["12"]; null for anything else.
     *
     * @return array{0: string, 1: string, 2: list<string>}|null
     */
    public static function parse(string $data): ?array
    {
        if (!str_starts_with($data, 'admin.')) {
            return null;
        }
        $parts = explode(':', substr($data, 6));
        $area = (string) array_shift($parts);
        if ('' === $area) {
            return null;
        }

        return [$area, (string) array_shift($parts), $parts];
    }
}
