<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Repositories\CartRepository;
use App\Repositories\InventoryRepository;
use App\Modules\Inventory\Application\InventoryManager;
use App\Modules\Platform\Infrastructure\InventoryStoreAdapter;
use Tests\TestCase;

/**
 * AC-D03 + F09: guarded reserve never oversells; ledger reconciles.
 */
final class InventoryRaceTest extends TestCase
{
    public function testReserveBeyondStockFailsWithoutOversell(): void
    {
        $repo = new InventoryRepository();
        $service = new InventoryManager(new InventoryStoreAdapter($repo));
        $carts = new CartRepository();
        $variant = $carts->createVariant([
            'id' => 'v-race-1',
            'product_id' => 'p-race-1',
            'sku' => 'RACE-001',
            'price_minor' => 1000,
        ]);
        $repo->setOnHand($variant['id'], 5, 'test');

        $service->reserve($variant['id'], 3, 'cart-a');
        $this->assertSame(2, $service->available($variant['id']));

        try {
            $service->reserve($variant['id'], 3, 'cart-b');
            $this->fail('Expected out of stock');
        } catch (\RuntimeException $e) {
            $this->assertSame('Out of stock', $e->getMessage());
        }
        $this->assertSame(2, $service->available($variant['id']));
    }

    public function testReleaseRestoresAvailability(): void
    {
        $repo = new InventoryRepository();
        $service = new InventoryManager(new InventoryStoreAdapter($repo));
        $carts = new CartRepository();
        $variant = $carts->createVariant([
            'id' => 'v-race-2',
            'product_id' => 'p-race-2',
            'sku' => 'RACE-002',
            'price_minor' => 500,
        ]);
        $repo->setOnHand($variant['id'], 4, 'test');
        $r = $service->reserve($variant['id'], 4, 'cart-c');
        $this->assertSame(0, $service->available($variant['id']));
        $service->release($r['id']);
        $this->assertSame(4, $service->available($variant['id']));
    }

    public function testSourcesHaveIndependentAvailabilityAndReservations(): void
    {
        $repo = new InventoryRepository();
        $service = new InventoryManager(new InventoryStoreAdapter($repo));
        $repo->setOnHand('v-source-1', 5, 'test', 'warehouse-a');
        $repo->setOnHand('v-source-1', 2, 'test', 'warehouse-b');

        $this->assertSame(5, $service->available('v-source-1', 'warehouse-a'));
        $this->assertSame(2, $service->available('v-source-1', 'warehouse-b'));
        $service->reserve('v-source-1', 4, 'cart-source', 900, 'warehouse-a');
        $this->assertSame(1, $service->available('v-source-1', 'warehouse-a'));
        $this->assertSame(2, $service->available('v-source-1', 'warehouse-b'));
    }

    public function testRepeatedReleaseDoesNotRestoreStockTwice(): void
    {
        $repo = new InventoryRepository();
        $service = new InventoryManager(new InventoryStoreAdapter($repo));
        $repo->setOnHand('v-source-2', 3, 'test', 'default');
        $reservation = $service->reserve('v-source-2', 2, 'cart-source-2');
        $service->release($reservation['id']);
        $service->release($reservation['id']);

        $this->assertSame(3, $service->available('v-source-2'));
    }

    public function testReservationRetryWithTheSameKeyDoesNotCreateAnotherHold(): void
    {
        $repo = new InventoryRepository();
        $service = new InventoryManager(new InventoryStoreAdapter($repo));
        $repo->setOnHand('v-reservation-retry', 3, 'test');

        $first = $service->reserve('v-reservation-retry', 2, 'cart-retry', 900, 'default', 'checkout-key:inventory:0', [
            'store_id' => 'store-a', 'channel_id' => 'web', 'locale' => 'en', 'currency' => 'USD',
        ]);
        $second = $service->reserve('v-reservation-retry', 2, 'cart-retry', 900, 'default', 'checkout-key:inventory:0', [
            'store_id' => 'store-a', 'channel_id' => 'web', 'locale' => 'en', 'currency' => 'USD',
        ]);

        self::assertSame($first['id'], $second['id']);
        self::assertSame(1, $service->available('v-reservation-retry'));
        self::assertSame('store-a', $second['store_id']);
        self::assertSame('web', $second['channel_id']);
    }

    public function testCrossSourceAllocationRollsBackEverySourceWhenTotalAtpIsInsufficient(): void
    {
        $repo = new InventoryRepository();
        $service = new InventoryManager(new InventoryStoreAdapter($repo));
        $repo->setOnHand('v-source-rollback', 2, 'test', 'warehouse-a');
        $repo->setOnHand('v-source-rollback', 2, 'test', 'warehouse-b');

        try {
            $service->reserveAcrossSources('v-source-rollback', 5, 'cart-rollback');
            $this->fail('Expected out of stock');
        } catch (\RuntimeException $e) {
            $this->assertSame('Out of stock', $e->getMessage());
        }

        $this->assertSame(2, $service->available('v-source-rollback', 'warehouse-a'));
        $this->assertSame(2, $service->available('v-source-rollback', 'warehouse-b'));
    }

    public function testSplitAllocationIsIdempotentAsACompleteGroup(): void
    {
        $repo = new InventoryRepository();
        $service = new InventoryManager(new InventoryStoreAdapter($repo));
        $repo->setOnHand('v-split', 2, 'test', 'warehouse-a');
        $repo->setOnHand('v-split', 3, 'test', 'warehouse-b');

        $first = $service->reserveAcrossSources('v-split', 5, 'cart-split', 900, ['allow_split' => true], 'split-key');
        $second = $service->reserveAcrossSources('v-split', 5, 'cart-split', 900, ['allow_split' => true], 'split-key');

        self::assertCount(2, $first);
        self::assertSame(array_map(static fn (array $row): string => (string) $row['id'], $first), array_map(static fn (array $row): string => (string) $row['id'], $second));
        self::assertSame(array_map(static fn (array $row): int => (int) $row['qty'], $first), array_map(static fn (array $row): int => (int) $row['qty'], $second));
        self::assertSame(0, $service->available('v-split', 'warehouse-a'));
        self::assertSame(0, $service->available('v-split', 'warehouse-b'));
    }

    public function testIdempotencyKeyCannotBeReusedForDifferentAllocation(): void
    {
        $repo = new InventoryRepository();
        $service = new InventoryManager(new InventoryStoreAdapter($repo));
        $repo->setOnHand('v-idempotency-conflict', 5, 'test', 'warehouse-a');

        $service->reserveAcrossSources('v-idempotency-conflict', 2, 'cart-idempotency', 900, [], 'allocation-key');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Idempotency key conflicts with the allocation request');
        $service->reserveAcrossSources('v-idempotency-conflict', 3, 'cart-idempotency', 900, [], 'allocation-key');
    }

    public function testSourcePolicyFiltersAllocationAndPersistsBackorder(): void
    {
        $repo = new InventoryRepository();
        $service = new InventoryManager(new InventoryStoreAdapter($repo));
        $repo->setOnHand('v-policy', 2, 'test', 'warehouse-a');
        $repo->setOnHand('v-policy', 4, 'test', 'warehouse-b');

        $result = $service->reserveAcrossSources('v-policy', 5, 'cart-policy', 900, ['allowed_source_ids' => ['warehouse-b'], 'backorder_policy' => 'backorder'], 'policy-key');

        self::assertSame('warehouse-b', $result[0]['source_id']);
        self::assertSame('backorder', $result[1]['state']);
        self::assertCount(2, $repo->findReservationsByAllocationKey('policy-key'));
    }

    public function testReservationTtlMustBePositive(): void
    {
        $service = new InventoryManager(new InventoryStoreAdapter(new InventoryRepository()));

        $this->expectException(\InvalidArgumentException::class);
        $service->reserve('v-invalid-ttl', 1, 'cart-invalid-ttl', 0);
    }

    public function testAtpReservationTtlMustBePositive(): void
    {
        $service = new InventoryManager(new InventoryStoreAdapter(new InventoryRepository()));

        $this->expectException(\InvalidArgumentException::class);
        $service->reserveAcrossSources('v-invalid-atp-ttl', 1, 'cart-invalid-atp-ttl', -1, [], 'atp-invalid-ttl');
    }

    public function testInventoryIdempotencyKeyIsBoundedAndValidated(): void
    {
        $service = new InventoryManager(new InventoryStoreAdapter(new InventoryRepository()));

        $this->expectException(\InvalidArgumentException::class);
        $service->reserveAcrossSources('v-invalid-key', 1, 'cart-invalid-key', 900, [], str_repeat('a', 129));
    }

    public function testTransferIdempotencyKeyIsBoundedAndValidated(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new InventoryRepository())->transfer([
            'variant_id' => 'v-invalid-transfer-key',
            'from_source_id' => 'warehouse-a',
            'to_source_id' => 'warehouse-b',
            'qty' => 1,
            'idempotency_key' => 'invalid key',
        ]);
    }

    public function testAbsoluteAdjustmentLocksTheRowAndRollsBackEvidenceOnInvariantFailure(): void
    {
        $repo = new InventoryRepository();
        $repo->setOnHand('v-adjust-atomic', 5, 'test');
        $service = new InventoryManager(new InventoryStoreAdapter($repo));
        $service->reserve('v-adjust-atomic', 3, 'cart-adjust');

        $before = (int) \Siro\Core\Database::connection()->query("SELECT COUNT(*) FROM inventory_adjustments WHERE variant_id = 'v-adjust-atomic'")->fetchColumn();
        $ledgerBefore = (int) \Siro\Core\Database::connection()->query("SELECT COUNT(*) FROM stock_ledger WHERE variant_id = 'v-adjust-atomic' AND reason = 'adjust'")->fetchColumn();
        try {
            $repo->adjustOnHand('v-adjust-atomic', 2, 'warehouse', 'default', 'cycle-count');
            self::fail('Expected reserved-stock invariant failure');
        } catch (\RuntimeException $e) {
            self::assertSame('on_hand cannot be below reserved quantity', $e->getMessage());
        }

        self::assertSame(5, (int) $repo->findItem('v-adjust-atomic')['on_hand']);
        self::assertSame($before, (int) \Siro\Core\Database::connection()->query("SELECT COUNT(*) FROM inventory_adjustments WHERE variant_id = 'v-adjust-atomic'")->fetchColumn());
        self::assertSame($ledgerBefore, (int) \Siro\Core\Database::connection()->query("SELECT COUNT(*) FROM stock_ledger WHERE variant_id = 'v-adjust-atomic' AND reason = 'adjust'")->fetchColumn());
    }

    public function testAdjustmentIdempotencyReplaysEvidenceAndRejectsDifferentPayload(): void
    {
        $repo = new InventoryRepository();
        $first = $repo->setOnHand('v-adjust-replay', 7, 'warehouse', 'default', 'adjustment-key');
        $second = $repo->setOnHand('v-adjust-replay', 7, 'warehouse', 'default', 'adjustment-key');

        self::assertSame($first['adjustment_id'], $second['id']);
        self::assertSame(7, (int) $repo->findItem('v-adjust-replay')['on_hand']);
        self::assertSame(1, (int) \Siro\Core\Database::connection()->query("SELECT COUNT(*) FROM inventory_adjustments WHERE idempotency_key = 'adjustment-key'")->fetchColumn());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Idempotency key conflicts with the adjustment request');
        $repo->setOnHand('v-adjust-replay', 8, 'warehouse', 'default', 'adjustment-key');
    }

    public function testReturnPostingIsIdempotentBeforeCompletionEvidenceIsWritten(): void
    {
        $repo = new InventoryRepository();
        $repo->setOnHand('v-return-replay', 4, 'test');

        $repo->receiveReturn('v-return-replay', 2, 'restock', 'return-1', 'return-line-1', 'rma');
        $repo->receiveReturn('v-return-replay', 2, 'restock', 'return-1', 'return-line-1', 'rma');

        self::assertSame(6, (int) $repo->findItem('v-return-replay')['on_hand']);
        self::assertSame(1, (int) \Siro\Core\Database::connection()->query("SELECT COUNT(*) FROM stock_ledger WHERE ref_type = 'return_line' AND ref_id = 'return-line-1'")->fetchColumn());
    }
}
