<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Contracts;

/** Optional provider contract for signed, streamed or ranged delivery. */
interface SignedDigitalDeliveryTransport extends DigitalDeliveryTransport
{
    /**
     * @param array<string, mixed> $asset
     * @return array{url: string, expires_at: string, headers?: array<string, string>}
     */
    public function signed(array $asset, int $ttlSeconds): array;

    /**
     * @param array<string, mixed> $asset
     * @return array{content: string, mime: string, filename: string, start: int, end: int, size: int}
     */
    public function readRange(array $asset, int $start, int $end): array;
}
