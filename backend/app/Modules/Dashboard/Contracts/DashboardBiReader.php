<?php

declare(strict_types=1);

namespace App\Modules\Dashboard\Contracts;

use App\Support\CommerceScope;

/** Read-only BI facts for the actionable admin dashboard. */
interface DashboardBiReader
{
    /**
     * @return list<array{currency: string, gross_minor: int, orders: int, aov_minor: int, refunds_minor: int, by_day: list<array{date: string, gross_minor: int, orders: int}>}>
     */
    public function sales(string $from, string $to, CommerceScope $scope): array;

    /**
     * @return array{sessions: int, product_views: int, add_to_cart: int, checkout_started: int, orders: int}
     */
    public function funnel(string $from, string $to, CommerceScope $scope): array;

    /**
     * @return list<array{product_id: string, name: string, slug: string, qty: int, revenue_minor: int}>
     */
    public function topProducts(string $from, string $to, CommerceScope $scope): array;

    /**
     * @return list<array{variant_id: string, name: string, sku: string, available: int}>
     */
    public function lowStock(CommerceScope $scope): array;

    /**
     * @return array{failed_payments: int, pending_refunds: int, queue_dead_letters: int, open_ops_alerts: int}
     */
    public function ops(CommerceScope $scope): array;
}
