<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\Payment\Application\PaymentReconciliationService;
use App\Modules\Payment\Contracts\PaymentReconciliationProvider;
use App\Modules\Payment\Contracts\PaymentReconciliationStore;
use App\Modules\Payment\Contracts\PaymentEvidenceStore;
use PHPUnit\Framework\TestCase;

final class RemainingOperationsTest extends TestCase
{
    public function testPaymentReconciliationNeverInvokesAProvider(): void
    {
        $store = new class implements PaymentReconciliationStore, PaymentEvidenceStore {
            public array $evidence = [];
            public function unresolved(): array { return [['id' => 'op-1']]; }
            public function reconcile(string $id, array $result): void {}
            public function recordEvidence(string $id, array $evidence): void { $this->evidence = $evidence; }
            public function evidence(string $id): ?array { return null; }
        };
        $provider = new class implements PaymentReconciliationProvider {
            public function reconcile(array $operation): array { throw new \LogicException('provider must not be called'); }
        };
        $result = (new PaymentReconciliationService($store))->reconcile($provider, true);
        self::assertSame(0, $result['resolved']);
        self::assertSame('local', $store->evidence['source']);
        self::assertTrue($store->evidence['provider_operation_skipped']);
    }
}
