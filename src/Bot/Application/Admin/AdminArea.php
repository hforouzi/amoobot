<?php

declare(strict_types=1);

namespace App\Bot\Application\Admin;

use App\Security\AdminPermission;

/**
 * One screen family of the bot admin ("admin.<area>:<verb>:..."). AdminBotHandler checks permissions() for the verb
 * before handle(); a typed answer to a prompt started by the area comes back through answer().
 */
interface AdminArea
{
    /** The <area> part of its callbacks. */
    public function area(): string;

    /**
     * The permissions a verb needs (any of them); an empty list means the verb does not exist.
     *
     * @return list<AdminPermission>
     */
    public function permissions(string $verb): array;

    public function handle(AdminRequest $request): void;

    /**
     * The permission a prompt of this kind needs, or null when the area has no such prompt.
     */
    public function promptPermission(string $kind): ?AdminPermission;

    public function answer(AdminRequest $request, string $kind, string $target, string $text): void;
}
