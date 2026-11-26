<?php
declare(strict_types=1);

namespace App\Modules\StorefrontConfig\Domain;

final class StorefrontConfig
{
    /** @param array<string, mixed> $config */
    public function __construct(
        public readonly array $config,
        public readonly int $version,
        public readonly bool $fallback = false,
    ) {}
}
