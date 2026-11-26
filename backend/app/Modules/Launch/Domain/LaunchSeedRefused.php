<?php

declare(strict_types=1);

namespace App\Modules\Launch\Domain;

use RuntimeException;

/** Raised when demo seeding would run against a real catalog. */
final class LaunchSeedRefused extends RuntimeException
{
    public static function activeCatalogTooLarge(int $activeProducts, int $limit = 50): self
    {
        return new self(sprintf(
            'Refusing to seed demo data: %d active products already exist (limit %d).',
            $activeProducts,
            $limit,
        ));
    }
}
