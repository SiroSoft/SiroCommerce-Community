<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Models\Order;
use App\Repositories\OrderRepository;
use App\Support\CommerceScope;
use Tests\TestCase;

final class AdminOrderScopeTest extends TestCase
{
    public function testOrderListAndDetailCannotCrossTenantOrSiteScope(): void
    {
        Order::create([
            'id' => 'scoped-order-a', 'number' => 'SCOPED-A', 'currency' => 'VND',
            'subtotal_minor' => 100, 'grand_total_minor' => 100, 'state' => 'pending',
            'idempotency_key' => 'scoped-key-a', 'store_id' => 'scope-store', 'channel_id' => 'web',
            'tenant_id' => 'tenant-a', 'site_id' => 'site-a',
        ]);
        Order::create([
            'id' => 'scoped-order-b', 'number' => 'SCOPED-B', 'currency' => 'VND',
            'subtotal_minor' => 100, 'grand_total_minor' => 100, 'state' => 'pending',
            'idempotency_key' => 'scoped-key-b', 'store_id' => 'scope-store', 'channel_id' => 'web',
            'tenant_id' => 'tenant-b', 'site_id' => 'site-b',
        ]);

        $scope = new CommerceScope('scope-store', 'web', 'en', 'VND', 'tenant-a', null, 'site-a');
        $repository = new OrderRepository();
        $result = $repository->paginateLatest(1, 25, $scope);

        self::assertSame(['scoped-order-a'], array_column($result['data'], 'id'));
        self::assertNotNull($repository->findInScope('scoped-order-a', $scope));
        self::assertNull($repository->findInScope('scoped-order-b', $scope));
    }
}
