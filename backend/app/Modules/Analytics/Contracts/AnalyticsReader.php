<?php
declare(strict_types=1);
namespace App\Modules\Analytics\Contracts;

interface AnalyticsReader
{
    /** @return array{data: array<int, array<string, mixed>>, meta: array<string, int>} */
    public function read(string $from, string $to, ?string $event, int $page, int $perPage, ?string $storeId = null, ?string $channelId = null): array;
}
