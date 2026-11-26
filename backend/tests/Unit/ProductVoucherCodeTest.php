<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\ProductVoucherCode;
use App\Modules\Catalog\Application\ProductLifecycleService;
use App\Repositories\ProductRepository;
use Tests\TestCase;

final class ProductVoucherCodeTest extends TestCase
{
    public function testVoucherReadProjectionExcludesSecretsAndReplayKeys(): void
    {
        (new ProductRepository())->store(['id' => 'unit-voucher-product', 'name' => 'Unit voucher', 'slug' => 'unit-voucher', 'type' => 'voucher', 'status' => 'draft']);
        ProductVoucherCode::create([
            'id' => 'unit-voucher-code', 'product_id' => 'unit-voucher-product', 'code_hash' => hash('sha256', 'UNIT-CODE'),
            'code_mask' => 'UN****DE', 'state' => 'available', 'created_at' => '2026-09-24 12:00:00', 'idempotency_key' => 'unit-replay-key',
        ]);

        $rows = (new ProductLifecycleService())->vouchers('unit-voucher-product');

        self::assertSame(['id', 'product_id', 'code_mask', 'state', 'expires_at', 'redeemed_by', 'redeemed_order_id', 'redeemed_at', 'created_at'], array_keys($rows[0]));
        self::assertArrayNotHasKey('code_hash', $rows[0]);
        self::assertArrayNotHasKey('idempotency_key', $rows[0]);
    }
}
