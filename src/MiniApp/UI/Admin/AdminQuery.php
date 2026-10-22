<?php

declare(strict_types=1);

namespace App\MiniApp\UI\Admin;

use App\MiniApp\Admin\AdminText;
use Symfony\Component\HttpFoundation\Request;

/**
 * The admin list pages' query parameters: ?q= (trimmed, at most 64 characters, Persian / Arabic digits as Latin) and
 * ?page= (0-based).
 */
final class AdminQuery
{
    public static function text(Request $request): ?string
    {
        $query = AdminText::latinDigits(trim($request->query->getString('q')));

        return '' === $query || mb_strlen($query) > 64 ? null : $query;
    }

    public static function page(Request $request): int
    {
        return min(10000, max(0, AdminText::integer($request->query->getString('page')) ?? 0));
    }
}
