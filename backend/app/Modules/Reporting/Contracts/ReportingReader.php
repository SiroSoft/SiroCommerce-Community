<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Contracts;

interface ReportingReader
{
    /** @return array<string, mixed> */
    public function overview(string $from, string $to, ?string $storeId = null, ?string $channelId = null): array;

    /** @return array<string, mixed> */
    public function failures(int $page, int $perPage): array;

    /** @return array<string, mixed> */
    public function search(string $query, string $type, int $page, int $perPage, ?string $storeId = null, ?string $channelId = null, ?string $locale = null, ?string $currency = null, array $facets = []): array;

    /** @return array<string, mixed> */
    public function advanced(string $dimension, string $from, string $to, ?string $storeId = null, ?string $channelId = null): array;
}
