<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Repositories\InventoryRepository;
use Siro\Core\Database;
use Tests\TestCase;

final class InventoryRepositoryBreadthTest extends TestCase
{
    private InventoryRepository $inventory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->inventory = new InventoryRepository();
    }

    public function testAdjustmentsIdempotencyGuardsAndEvidence(): void
    {
        self::assertNull($this->inventory->findItem('inv-a'));
        $item = $this->inventory->ensureItem('inv-a');
        self::assertSame(0, (int) $item['on_hand']);
        self::assertSame($item['id'], $this->inventory->ensureItem('inv-a')['id']);

        try {
            $this->inventory->setOnHand('inv-a', -1, 'tester');
            self::fail('Negative stock must be rejected.');
        } catch (\InvalidArgumentException $error) {
            self::assertStringContainsString('negative', $error->getMessage());
        }

        $adjusted = $this->inventory->setOnHand('inv-a', 10, 'tester', 'default', 'inv-adj-1');
        self::assertSame(10, $adjusted['on_hand']);
        self::assertSame(10, $adjusted['delta']);
        $replayed = $this->inventory->setOnHand('inv-a', 10, 'tester', 'default', 'inv-adj-1');
        self::assertSame('admin-adjustment', $replayed['evidence']['reason'] ?? null);
        try {
            $this->inventory->setOnHand('inv-a', 11, 'tester', 'default', 'inv-adj-1');
            self::fail('Conflicting idempotency replay must be rejected.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('conflicts', $error->getMessage());
        }

        $this->inventory->tryReserve('inv-a', 4);
        try {
            $this->inventory->setOnHand('inv-a', 2, 'tester');
            self::fail('On-hand cannot drop below reserved stock.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('below reserved', $error->getMessage());
        }
        $this->inventory->releaseReserved('inv-a', 4);

        $availability = $this->inventory->sourceAvailability('inv-a');
        self::assertSame('default', $availability[0]['source_id'] ?? null);
        $this->inventory->lockSources('inv-a', ['default', 'default', '']);
        self::assertSame('default', $this->inventory->setSourcePriority('inv-a', 'default', 2)['source_id']);
        self::assertSame(3, $this->inventory->setSourcePriority('inv-a', 'default', 3)['priority']);
        try {
            $this->inventory->setSourcePriority('inv-a', '', 1);
            self::fail('Empty source ids must be rejected.');
        } catch (\InvalidArgumentException $error) {
            self::assertStringContainsString('priority', $error->getMessage());
        }
    }

    public function testTransfersReservationsAndReturnPosting(): void
    {
        $this->inventory->setOnHand('inv-from', 5, 'tester', 'a');
        $this->inventory->setOnHand('inv-from', 0, 'tester', 'b');

        foreach ([
            ['from_source_id' => '', 'to_source_id' => 'b', 'variant_id' => 'inv-from', 'qty' => 1, 'idempotency_key' => 'tr-1'],
            ['from_source_id' => 'a', 'to_source_id' => 'a', 'variant_id' => 'inv-from', 'qty' => 1, 'idempotency_key' => 'tr-1'],
            ['from_source_id' => 'a', 'to_source_id' => 'b', 'variant_id' => 'inv-from', 'qty' => 0, 'idempotency_key' => 'tr-1'],
            ['from_source_id' => 'a', 'to_source_id' => 'b', 'variant_id' => 'inv-from', 'qty' => 1, 'idempotency_key' => 'bad key'],
        ] as $invalid) {
            try {
                $this->inventory->transfer($invalid);
                self::fail('Invalid transfer must be rejected.');
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }

        try {
            $this->inventory->transfer(['from_source_id' => 'a', 'to_source_id' => 'b', 'variant_id' => 'inv-from', 'qty' => 99, 'idempotency_key' => 'tr-insufficient']);
            self::fail('Transfers beyond available stock must fail.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('Insufficient', $error->getMessage());
        }

        $transfer = $this->inventory->transfer([
            'from_source_id' => 'a', 'to_source_id' => 'b', 'variant_id' => 'inv-from', 'qty' => 3,
            'idempotency_key' => 'tr-1', 'actor' => 'tester', 'reason' => 'restock',
        ]);
        self::assertSame('completed', $transfer['state']);
        self::assertSame(2, $this->inventory->findItem('inv-from', 'a')['on_hand']);
        self::assertSame(3, $this->inventory->findItem('inv-from', 'b')['on_hand']);
        self::assertSame($transfer['id'], $this->inventory->transfer([
            'from_source_id' => 'a', 'to_source_id' => 'b', 'variant_id' => 'inv-from', 'qty' => 3, 'idempotency_key' => 'tr-1',
        ])['id']);
        try {
            $this->inventory->transfer(['from_source_id' => 'a', 'to_source_id' => 'b', 'variant_id' => 'inv-from', 'qty' => 2, 'idempotency_key' => 'tr-1']);
            self::fail('Replayed transfers with different quantities must conflict.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('conflicts', $error->getMessage());
        }

        self::assertSame(0, $this->inventory->tryReserve('inv-from', 99, 'b'));
        self::assertSame(1, $this->inventory->tryReserve('inv-from', 1, 'b'));
        $this->inventory->releaseReserved('inv-from', 1, 'b');
        try {
            $this->inventory->releaseReserved('inv-from', 99, 'b');
            self::fail('Releasing more than reserved must fail.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('invariant', $error->getMessage());
        }
        $this->inventory->tryReserve('inv-from', 1, 'b');
        $this->inventory->commitReserved('inv-from', 1, 'b');
        try {
            $this->inventory->commitReserved('inv-from', 99, 'b');
            self::fail('Committing more than reserved must fail.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('invariant', $error->getMessage());
        }

        $reservation = $this->inventory->createReservation([
            'id' => 'reservation-1', 'variant_id' => 'inv-from', 'source_id' => 'b', 'cart_id' => 'cart-1',
            'qty' => 1, 'state' => 'held', 'idempotency_key' => 'res-key-1', 'allocation_key' => 'alloc-1',
        ]);
        self::assertSame('reservation-1', $reservation['id']);
        self::assertNotNull($this->inventory->findReservation('reservation-1'));
        self::assertNull($this->inventory->findReservation('missing-reservation'));
        self::assertSame('reservation-1', $this->inventory->findReservationByIdempotency('res-key-1')['id'] ?? null);
        self::assertCount(1, $this->inventory->findReservationsByAllocationKey('alloc-1'));
        self::assertCount(1, $this->inventory->heldForCart('cart-1'));
        self::assertSame(0, $this->inventory->markReservation('missing-reservation', 'committed'));
        self::assertSame(1, $this->inventory->markReservation('reservation-1', 'committed', 'order-1'));
        self::assertSame(0, $this->inventory->markReservation('reservation-1', 'released'));

        $this->inventory->appendLedger(['id' => 'ledger-manual', 'variant_id' => 'inv-from', 'source_id' => 'b', 'delta' => 1, 'reason' => 'manual', 'ref_type' => 'manual', 'ref_id' => 'manual-1', 'actor' => 'tester', 'created_at' => date('Y-m-d H:i:s')]);

        foreach ([
            ['qty' => 0, 'disposition' => 'restock'],
            ['qty' => 1, 'disposition' => 'bogus'],
        ] as $invalidReturn) {
            try {
                $this->inventory->receiveReturn('inv-from', $invalidReturn['qty'], $invalidReturn['disposition'], 'return-1', 'line-1', 'tester', 'b');
                self::fail('Invalid return postings must be rejected.');
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }

        $before = $this->inventory->findItem('inv-from', 'b')['on_hand'];
        $this->inventory->receiveReturn('inv-from', 2, 'restock', 'return-1', 'line-1', 'tester', 'b');
        self::assertSame((int) $before + 2, (int) $this->inventory->findItem('inv-from', 'b')['on_hand']);
        $this->inventory->receiveReturn('inv-from', 2, 'restock', 'return-1', 'line-1', 'tester', 'b');
        self::assertSame((int) $before + 2, (int) $this->inventory->findItem('inv-from', 'b')['on_hand']);
        try {
            $this->inventory->receiveReturn('inv-from', 3, 'restock', 'return-1', 'line-1', 'tester', 'b');
            self::fail('Conflicting return evidence must be rejected.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('conflicts', $error->getMessage());
        }
        $this->inventory->receiveReturn('inv-from', 5, 'damaged', 'return-2', 'line-2', 'tester', 'b');
        $ledger = Database::select("SELECT delta FROM stock_ledger WHERE ref_type = 'return_line' AND ref_id = ?", ['line-2']);
        self::assertSame(0, (int) ($ledger[0]['delta'] ?? -1));
    }
}
