<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Application;

use App\Modules\Reporting\Contracts\ReportingReader;
use App\Support\ReportingScope;

final class ReportingService
{
    public function __construct(private readonly ReportingReader $reader) {}

    /** @return array<string, mixed> */
    public function overview(?string $from, ?string $to, ?string $storeId = null, ?string $channelId = null): array
    {
        [$storeId, $channelId] = ReportingScope::validate($storeId, $channelId);
        [$start, $end] = $this->window($from, $to);
        return $this->reader->overview($start, $end, $storeId, $channelId);
    }

    /** @return array<string, mixed> */
    public function failures(int $page, int $perPage): array
    {
        return $this->reader->failures(max(1, $page), min(100, max(1, $perPage)));
    }

    /** @return array<string, mixed> */
    public function search(string $query, string $type, int $page, int $perPage, ?string $storeId = null, ?string $channelId = null, ?string $locale = null, ?string $currency = null, array $facets = []): array
    {
        [$storeId, $channelId] = ReportingScope::validate($storeId, $channelId);
        return $this->reader->search($query, $type, max(1, $page), min(100, max(1, $perPage)), $storeId, $channelId, $locale, $currency, $facets);
    }

    /** @return array<string, mixed> */
    public function advanced(string $dimension, ?string $from, ?string $to, ?string $storeId = null, ?string $channelId = null): array
    {
        [$storeId, $channelId] = ReportingScope::validate($storeId, $channelId);
        $allowed = ['profitability', 'cohorts', 'retention', 'repeat-purchase', 'attribution', 'sla', 'inventory-turnover', 'widgets', 'insights'];
        if (!in_array($dimension, $allowed, true)) throw new \InvalidArgumentException('Unknown reporting dimension');
        [$start, $end] = $this->window($from, $to);
        return $this->reader->advanced($dimension, $start, $end, $storeId, $channelId);
    }

    /** @return array{0:string,1:string} */
    private function window(?string $from, ?string $to): array
    {
        $endTimestamp = $to === null ? time() : strtotime($to);
        $startTimestamp = $from === null ? strtotime('-30 days') : strtotime($from);
        if ($endTimestamp === false || $startTimestamp === false) {
            throw new \InvalidArgumentException('Reporting window must contain valid dates');
        }
        if ($startTimestamp > $endTimestamp || ($endTimestamp - $startTimestamp) > 366 * 86400) {
            throw new \InvalidArgumentException('Reporting window must be 1-366 days');
        }
        return [date('Y-m-d 00:00:00', $startTimestamp), date('Y-m-d 23:59:59', $endTimestamp)];
    }
}
