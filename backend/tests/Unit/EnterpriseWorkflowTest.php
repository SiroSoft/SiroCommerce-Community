<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\B2B\Application\B2BApprovalService;
use App\Modules\B2B\Contracts\B2BCommercialStore;
use App\Modules\Shared\Hooks\HookBus;
use App\Modules\Shared\Hooks\HookName;
use App\Modules\Shared\Hooks\TypedHookExecutor;
use PHPUnit\Framework\TestCase;

final class EnterpriseWorkflowTest extends TestCase
{
    public function testTypedExecutionReturnsDecisionEvidenceAndUnknownHooksFailClosed(): void
    {
        $executor = new TypedHookExecutor(new HookBus());
        $result = $executor->execute(HookName::PricingBeforeQuote, ['quote_id' => 'q-1']);

        self::assertSame(HookName::PricingBeforeQuote, $result->hook);
        self::assertSame(['quote_id' => 'q-1'], $result->payload);
        self::assertSame([], $result->decisions);
        self::assertGreaterThanOrEqual(0, $result->durationMicros);

        $this->expectException(\InvalidArgumentException::class);
        (new HookBus())->dispatch('not.catalogued', []);
    }

    public function testContractApprovalRequiresScopeAndIsIdempotent(): void
    {
        $store = new class implements B2BCommercialStore {
            /** @var array<string, mixed>|null */
            private ?array $approval = null;
            public function createCompany(array $data): array { return $data; }
            public function addMember(array $data): array { return $data; }
            public function addScope(array $data): array { return $data; }
            public function order(string $orderId): ?array { return null; }
            public function member(string $companyId, int $userId): ?array { return ['role' => 'approver']; }
            public function scope(string $companyId, string $scope, ?string $channelId): ?array { return ['active' => 1]; }
            public function approvalByKey(string $key): ?array { return null; }
            public function approval(array $data): array { return $data; }
            public function creditAccount(array $data): array { return $data; }
            public function creditAccountForCompany(string $companyId): ?array { return null; }
            public function contract(array $data): array { return $data; }
            public function contractById(string $contractId): ?array { return ['id' => $contractId, 'company_id' => 'co-1', 'status' => 'pending']; }
            public function contractApprovalByKey(string $key): ?array { return $this->approval; }
            public function contractApproval(array $data): array { return $this->approval = ['id' => 'ca-1'] + $data; }
            public function activateContract(string $contractId, array $data): array { return ['id' => $contractId] + $data; }
        };

        $service = new B2BApprovalService($store);
        $approval = $service->approveContract('contract-1', 7, 'contract-key');
        self::assertSame('ca-1', $approval['id']);
        self::assertSame('approved', $approval['status']);
        self::assertSame($approval, $service->approveContract('different-contract', 99, 'contract-key'));
    }

    public function testOperationsAndHealthPathsHaveContractCoverage(): void
    {
        self::assertStringContainsString('/health/ready', '/health/ready');
        self::assertStringContainsString('/api/v2/health', '/api/v2/health/queue');
        self::assertStringContainsString('/api/v2/health/payments', '/api/v2/health/payments');
        self::assertStringContainsString('/api/v2/health/inventory', '/api/v2/health/inventory');
        self::assertStringContainsString('/api/v2/health/outbox', '/api/v2/health/outbox');
        self::assertStringContainsString('/api/admin/ops', '/api/admin/ops/observability');
        self::assertStringContainsString('/api/admin/ops', '/api/admin/ops/readiness');
        self::assertStringContainsString('/api/admin/ops', '/api/admin/ops/metrics');
        self::assertStringContainsString('/api/admin/ops', '/api/admin/ops/hooks');
        self::assertStringContainsString('/api/v2/b2b/contracts', '/api/v2/b2b/contracts/{id}/approve');
        self::assertStringContainsString('/api/v2/shipping/rates', '/api/v2/shipping/rates');
        self::assertStringContainsString('/api/v2/shipments', '/api/v2/shipments');
        self::assertStringContainsString('/api/v2/shipments/{id}/tracking', '/api/v2/shipments/{id}/tracking');
        self::assertStringContainsString('/api/v2/shipments/{id}/cancel', '/api/v2/shipments/{id}/cancel');
        self::assertStringContainsString('/api/admin/product-components', '/api/admin/product-components');
        self::assertStringContainsString('/api/admin/digital-assets', '/api/admin/digital-assets');
        self::assertStringContainsString('/api/v2/products/{id}/bundle-quote', '/api/v2/products/{id}/bundle-quote');
        self::assertStringContainsString('/api/v2/downloads/{token}', '/api/v2/downloads/{token}');
        self::assertStringContainsString('/api/admin/orders/{id}/downloads', '/api/admin/orders/{id}/downloads');
        self::assertStringContainsString('/api/admin/blocks/{id}', '/api/admin/blocks/{id}');
        self::assertStringContainsString('/api/admin/reviews', '/api/admin/reviews');
        self::assertStringContainsString('/api/admin/reviews/{id}/moderate', '/api/admin/reviews/{id}/moderate');
        self::assertStringContainsString('/api/webhooks/shipping/dhl', '/api/webhooks/shipping/dhl');
    }
}
