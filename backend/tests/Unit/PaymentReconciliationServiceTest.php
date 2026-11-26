<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\Payment\Application\PaymentReconciliationService;
use App\Modules\Payment\Contracts\PaymentCaptureStore;
use App\Modules\Payment\Contracts\PaymentEvidenceStore;
use App\Modules\Payment\Contracts\PaymentReconciliationProvider;
use App\Modules\Payment\Contracts\PaymentReconciliationStore;
use App\Modules\Payment\Contracts\PaymentStore;
use PHPUnit\Framework\TestCase;

final class PaymentReconciliationServiceTest extends TestCase
{
    public function testDefinitiveCaptureResolvesOperationAndAdvancesPayment(): void
    {
        $store = new InMemoryReconciliationStore([
            ['id' => 'op-capture', 'payment_id' => 'pay-capture', 'type' => 'capture', 'amount_minor' => 400, 'state' => 'unknown'],
        ]);
        $payments = new InMemoryPaymentStore();
        $result = (new PaymentReconciliationService($store, $payments))->reconcile(
            new FixedReconciliationProvider(['state' => 'captured', 'provider_ref' => 'provider-capture']),
            true,
        );

        self::assertSame(1, $result['resolved']);
        self::assertSame('captured', $store->resolved['op-capture']['state']);
        self::assertSame('captured', $payments->payment['state']);
        self::assertSame(400, $payments->payment['captured_amount_minor']);
        self::assertSame('provider-capture', $payments->payment['provider_ref']);
    }

    public function testPendingResultRemainsUnknownAndOnlyStoresEvidence(): void
    {
        $store = new InMemoryReconciliationStore([
            ['id' => 'op-pending', 'payment_id' => 'pay-pending', 'type' => 'capture', 'amount_minor' => 400, 'state' => 'unknown'],
        ]);
        $result = (new PaymentReconciliationService($store))->reconcile(
            new FixedReconciliationProvider(['state' => 'unknown', 'provider_status' => 'pending']),
            true,
        );

        self::assertSame(0, $result['resolved']);
        self::assertSame(1, $result['unknown']);
        self::assertSame([], $store->resolved);
        self::assertSame('pending', $store->evidence['provider_status']);
    }
}

final class InMemoryReconciliationStore implements PaymentReconciliationStore, PaymentEvidenceStore
{
    /** @var array<string, array<string, mixed>> */
    public array $resolved = [];
    /** @var array<string, mixed> */
    public array $evidence = [];

    /** @param array<int, array<string, mixed>> $rows */
    public function __construct(private readonly array $rows) {}
    public function unresolved(): array { return $this->rows; }
    public function reconcile(string $id, array $result): void { $this->resolved[$id] = $result; }
    public function recordEvidence(string $id, array $evidence): void { $this->evidence = $evidence; }
}

final class FixedReconciliationProvider implements PaymentReconciliationProvider
{
    /** @param array<string, mixed> $result */
    public function __construct(private readonly array $result) {}
    public function reconcile(array $operation): array { return $this->result; }
}

final class InMemoryPaymentStore implements PaymentStore, PaymentCaptureStore
{
    /** @var array<string, mixed> */
    public array $payment = ['id' => 'pay-capture', 'state' => 'authorized', 'captured_amount_minor' => 0, 'amount_minor' => 400];
    public function find(string $id): ?array { return $id === $this->payment['id'] ? $this->payment : null; }
    public function setState(string $id, string $state, ?string $providerRef = null): void { $this->payment['state'] = $state; $this->payment['provider_ref'] = $providerRef; }
    public function setCapturedAmount(string $id, int $capturedAmountMinor): void { $this->payment['captured_amount_minor'] = $capturedAmountMinor; }
    public function applyCapture(string $id, int $amountMinor, ?string $providerRef = null): void { $this->payment['captured_amount_minor'] += $amountMinor; $this->setState($id, 'captured', $providerRef); }
}
