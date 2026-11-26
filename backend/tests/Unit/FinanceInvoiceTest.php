<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\Finance\Application\InvoiceManager;
use App\Modules\Finance\Contracts\InvoiceStore;
use App\Modules\Platform\Infrastructure\InvoiceStoreAdapter;
use App\Models\Invoice;
use Tests\TestCase;

final class FinanceInvoiceTest extends TestCase
{
    public function testInvoiceRequiresMatchingPaymentEvidence(): void
    {
        $store = new FakeInvoiceStore([
            'order' => ['currency' => 'USD', 'grand_total_minor' => 1250],
            'payment' => ['order_id' => 'order-1', 'currency' => 'USD', 'amount_minor' => 1200],
        ]);

        $this->expectException(\InvalidArgumentException::class);
        (new InvoiceManager($store))->create('order-1', 'payment-1', 'invoice-1');
    }

    public function testInvoiceCannotBeIssuedBeforeCapture(): void
    {
        $store = new FakeInvoiceStore([
            'id' => 'invoice-1', 'state' => 'draft', 'amount_minor' => 1250, 'currency' => 'USD',
            'evidence_json' => json_encode(['payment' => ['state' => 'authorized', 'amount_minor' => 1250, 'currency' => 'USD']], JSON_THROW_ON_ERROR),
        ]);

        $this->expectException(\InvalidArgumentException::class);
        (new InvoiceManager($store))->issue('invoice-1', 'issue-1');
    }

    public function testIssuingAnInvoiceIsIdempotentAndDoesNotRewriteFinalizedState(): void
    {
        $store = new FakeInvoiceStore([
            'id' => 'invoice-1', 'state' => 'draft', 'amount_minor' => 1250, 'currency' => 'USD',
            'evidence_json' => json_encode(['payment' => ['state' => 'captured', 'amount_minor' => 1250, 'currency' => 'USD']], JSON_THROW_ON_ERROR),
        ]);
        $manager = new InvoiceManager($store);

        self::assertSame('issued', $manager->issue('invoice-1', 'issue-1')['state']);
        self::assertSame('issued', $manager->issue('invoice-1', 'different-key')['state']);
        self::assertSame(1, $store->stateChanges);
    }

    public function testInvoiceHeaderRollsBackWhenLineSnapshotFails(): void
    {
        $this->expectException(\Throwable::class);
        try {
            (new InvoiceStoreAdapter())->create([
                'id' => 'invoice-atomic-1', 'number' => 'INV-ATOMIC-1', 'order_id' => 'order-atomic-1',
                'payment_id' => 'payment-atomic-1', 'state' => 'draft', 'currency' => 'USD', 'amount_minor' => 100,
                'evidence_json' => '{}', 'idempotency_key' => 'invoice-atomic-1', 'line_snapshots' => [['name' => 'invalid']],
                'tax_snapshots' => [],
            ]);
        } finally {
            self::assertNull(Invoice::find('invoice-atomic-1'));
        }
    }

    public function testInvoiceRejectsIdempotencyKeyReuseForAnotherOrder(): void
    {
        $store = new FakeInvoiceStore(['id' => 'invoice-1', 'order_id' => 'order-1', 'payment_id' => 'payment-1'], ['order_id' => 'order-1', 'payment_id' => 'payment-1']);
        $this->expectException(\InvalidArgumentException::class);
        (new InvoiceManager($store))->create('order-2', 'payment-2', 'invoice-key');
    }

    public function testInvoiceRequiresTaxEvidenceForTaxedOrder(): void
    {
        $store = new FakeInvoiceStore(['order' => ['currency' => 'USD', 'minor_unit' => 2, 'subtotal_minor' => 1000, 'discount_minor' => 0, 'shipping_minor' => 0, 'tax_minor' => 100, 'grand_total_minor' => 1100], 'payment' => ['order_id' => 'order-1', 'currency' => 'USD', 'minor_unit' => 2, 'amount_minor' => 1100]]);
        $this->expectException(\InvalidArgumentException::class);
        (new InvoiceManager($store))->create('order-1', 'payment-1', 'invoice-tax-key');
    }
}

/** @implements InvoiceStore */
final class FakeInvoiceStore implements InvoiceStore
{
    /** @param array<string, mixed> $row */
    public function __construct(private array $row, private ?array $idempotent = null) {}
    public int $stateChanges = 0;
    public function transaction(callable $callback): mixed { return $callback(); }
    public function byIdempotency(string $key): ?array { return $this->idempotent; }
    public function find(string $id): ?array { return $this->row['id'] === $id ? $this->row : null; }
    public function create(array $data): array { $this->row = $data; return $data; }
    public function setState(string $id, string $state, ?string $issuedAt = null): void { $this->row['state'] = $state; $this->row['issued_at'] = $issuedAt; $this->stateChanges++; }
    public function creditedAmount(string $id): int { return 0; }
    public function evidence(string $orderId, string $paymentId): array { return $this->row; }
    public function reconcilePayment(string $paymentId, string $paymentState, ?string $providerRef, array $evidence = []): void {}
}
