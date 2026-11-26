<?php

declare(strict_types=1);

namespace App\Modules\Dashboard\Application;

use App\Modules\Dashboard\Contracts\DashboardBiReader;
use App\Support\CommerceScope;
use Siro\Core\Cache;

/**
 * Actionable business pulse for the admin dashboard. The reader is best-effort:
 * a failed aggregate degrades to zeros rather than failing the whole request.
 */
final class DashboardBiService
{
    private const CACHE_TTL_SECONDS = 60;
    private const RANGES = ['7d' => 7, '30d' => 30, '90d' => 90];

    public function __construct(private readonly DashboardBiReader $reader) {}

    /** @return array<string, mixed> */
    public function bi(string $range, CommerceScope $scope): array
    {
        [$from, $to] = self::windowForRange($range);
        $key = 'admin:dashboard:bi:v1:' . $range . ':' . $scope->key();
        /** @var array<string, mixed> $payload */
        $payload = Cache::remember($key, self::CACHE_TTL_SECONDS, function () use ($range, $from, $to, $scope): array {
            $blocks = $this->attempt(fn (): array => $this->reader->sales($from, $to, $scope), []);
            return [
                'range' => $range,
                'window' => ['from' => $from, 'to' => $to],
                'sales' => count($blocks) === 1 ? $blocks[0] : ($blocks === [] ? $this->zeroSalesBlock($scope->currency) : $blocks),
                'sales_by_currency' => $blocks,
                'funnel' => $this->normalizeFunnel($this->attempt(fn (): array => $this->reader->funnel($from, $to, $scope), [])),
                'top_products' => $this->attempt(fn (): array => $this->reader->topProducts($from, $to, $scope), []),
                'low_stock' => $this->attempt(fn (): array => $this->reader->lowStock($scope), []),
                'ops' => $this->normalizeOps($this->attempt(fn (): array => $this->reader->ops($scope), [])),
                'generated_at' => gmdate('c'),
            ];
        });
        return $payload;
    }

    /**
     * Calendar-day window used by the range selector. 7d/30d/90d include today.
     *
     * @return array{0: string, 1: string}
     */
    public static function windowForRange(string $range, ?int $now = null): array
    {
        $days = self::RANGES[$range] ?? null;
        if ($days === null) throw new \InvalidArgumentException('Range must be one of 7d, 30d, 90d');
        $now ??= time();
        return [
            date('Y-m-d 00:00:00', strtotime('-' . ($days - 1) . ' days', $now)),
            date('Y-m-d 23:59:59', $now),
        ];
    }

    /** @return array<string, mixed> */
    public function emptyPayload(string $range, CommerceScope $scope): array
    {
        return [
            'range' => $range,
            'window' => ['from' => '', 'to' => ''],
            'sales' => $this->zeroSalesBlock($scope->currency),
            'sales_by_currency' => [],
            'funnel' => $this->normalizeFunnel([]),
            'top_products' => [],
            'low_stock' => [],
            'ops' => $this->normalizeOps([]),
            'generated_at' => gmdate('c'),
        ];
    }

    /**
     * @template T of array
     * @param callable(): T $read
     * @param T $fallback
     * @return T
     */
    private function attempt(callable $read, array $fallback): array
    {
        try {
            return $read();
        } catch (\Throwable) {
            return $fallback;
        }
    }

    /** @return array<string, mixed> */
    private function zeroSalesBlock(string $currency): array
    {
        return ['currency' => strtoupper($currency), 'gross_minor' => 0, 'orders' => 0, 'aov_minor' => 0, 'refunds_minor' => 0, 'by_day' => []];
    }

    /**
     * @param array<string, mixed> $row
     * @return array{sessions: int, product_views: int, add_to_cart: int, checkout_started: int, orders: int}
     */
    private function normalizeFunnel(array $row): array
    {
        return [
            'sessions' => is_int($row['sessions'] ?? null) ? $row['sessions'] : 0,
            'product_views' => is_int($row['product_views'] ?? null) ? $row['product_views'] : 0,
            'add_to_cart' => is_int($row['add_to_cart'] ?? null) ? $row['add_to_cart'] : 0,
            'checkout_started' => is_int($row['checkout_started'] ?? null) ? $row['checkout_started'] : 0,
            'orders' => is_int($row['orders'] ?? null) ? $row['orders'] : 0,
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array{failed_payments: int, pending_refunds: int, queue_dead_letters: int, open_ops_alerts: int}
     */
    private function normalizeOps(array $row): array
    {
        return [
            'failed_payments' => is_int($row['failed_payments'] ?? null) ? $row['failed_payments'] : 0,
            'pending_refunds' => is_int($row['pending_refunds'] ?? null) ? $row['pending_refunds'] : 0,
            'queue_dead_letters' => is_int($row['queue_dead_letters'] ?? null) ? $row['queue_dead_letters'] : 0,
            'open_ops_alerts' => is_int($row['open_ops_alerts'] ?? null) ? $row['open_ops_alerts'] : 0,
        ];
    }
}
