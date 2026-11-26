<?php

declare(strict_types=1);

namespace App\Support;

/** Keeps offset pagination bounded even when a repository is called directly. */
final class PaginationBounds
{
    public const MAX_PAGE = 10000;
    public const MAX_PER_PAGE = 100;

    public static function page(int $page): int
    {
        return min(max($page, 1), self::MAX_PAGE);
    }

    public static function perPage(int $perPage): int
    {
        return min(max($perPage, 1), self::MAX_PER_PAGE);
    }
}
