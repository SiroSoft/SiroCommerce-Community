<?php
declare(strict_types=1);
namespace App\Modules\Analytics\Contracts;
interface AnalyticsStore
{
    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function record(array $data): array;
    public function count(string $event): int;
}
