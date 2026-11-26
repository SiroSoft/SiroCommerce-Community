<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Repositories\OrderRepository;
use App\Support\CommerceScope;
use Tests\TestCase;

final class OrderOperationsTest extends TestCase
{
    public function testScopedOrderSearchFiltersAndRefundReplayKeyAreStable(): void
    {
        $orders = new OrderRepository();
        $orders->createOrder([
            'id' => 'ops-order-1', 'number' => 'SC-OPS-001', 'customer_id' => 'customer-1', 'guest_email' => 'ops@example.test',
            'currency' => 'VND', 'subtotal_minor' => 1000, 'grand_total_minor' => 1000, 'state' => 'paid',
            'idempotency_key' => 'ops-place-1', 'store_id' => 'store-ops', 'channel_id' => 'web', 'created_at' => '2026-09-20 10:00:00',
        ]);
        $orders->createOrder([
            'id' => 'ops-order-2', 'number' => 'SC-OPS-002', 'customer_id' => 'customer-2', 'guest_email' => 'other@example.test',
            'currency' => 'VND', 'subtotal_minor' => 1000, 'grand_total_minor' => 1000, 'state' => 'cancelled',
            'idempotency_key' => 'ops-place-2', 'store_id' => 'other-store', 'channel_id' => 'web', 'created_at' => '2026-09-21 10:00:00',
        ]);

        $result = $orders->paginateLatest(1, 20, new CommerceScope('store-ops', 'web', 'en', 'VND'), [
            'q' => 'SC-OPS-001', 'status' => 'paid',
        ]);
        self::assertSame(1, $result['meta']['total']);
        self::assertSame('ops-order-1', $result['data'][0]['id']);

        $orders->createRefund(['id' => 'ops-refund-1', 'order_id' => 'ops-order-1', 'payment_id' => 'ops-payment-1', 'amount_minor' => 1000, 'reason' => 'requested', 'state' => 'done', 'idempotency_key' => 'ops-refund-key']);
        self::assertSame('ops-refund-1', $orders->findRefundByIdempotency('ops-refund-key')['id']);
        self::assertNull($orders->findRefundByIdempotency('unknown-refund-key'));
    }
}
