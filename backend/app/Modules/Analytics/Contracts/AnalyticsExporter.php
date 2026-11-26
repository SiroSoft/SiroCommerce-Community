<?php
declare(strict_types=1);
namespace App\Modules\Analytics\Contracts;

interface AnalyticsExporter
{
    /** @return array{schema_version: int, exported_at: string, records: array<int, array<string, mixed>>} */
    public function export(string $from, string $to, ?string $event, int $page, int $perPage): array;
}
