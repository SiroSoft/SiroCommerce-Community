<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\Ai\Application\AiSuggestionService;
use App\Modules\Ai\Contracts\AiAuditWriter;
use App\Modules\Ai\Contracts\AiCostLedger;
use App\Modules\Ai\Contracts\AiSuggestionGateway;
use App\Modules\Ai\Contracts\AiSuggestionRequest;
use App\Modules\Ai\Contracts\AiSuggestionResult;
use App\Modules\Ai\Infrastructure\UnavailableAiSuggestionGateway;
use App\Support\CommerceScope;
use PHPUnit\Framework\TestCase;

final class AiSuggestionContractTest extends TestCase
{
    public function testNativeGatewayNeverCallsAnExternalProvider(): void
    {
        $scope = new CommerceScope('store', 'channel', 'en', 'USD', 'tenant');
        $result = (new UnavailableAiSuggestionGateway())->suggest(new AiSuggestionRequest('catalog.copy', ['name' => 'Shoe'], $scope, 'actor'));
        self::assertSame('provider_unavailable', $result->status);
        self::assertFalse($result->toArray()['autonomous_mutation']);
    }

    public function testMutationLikeTasksAreRejectedBeforeGateway(): void
    {
        $gateway = new class implements AiSuggestionGateway { public function suggest(AiSuggestionRequest $request): AiSuggestionResult { throw new \RuntimeException('gateway must not be reached'); } };
        $audit = new class implements AiAuditWriter { public function record(string $tenantId, string $actorId, string $task, string $status, array $metadata = []): void {} };
        $costs = new class implements AiCostLedger { public function remainingMinor(string $tenantId): int { return 0; } public function reserveMinor(string $tenantId, int $amountMinor): bool { return false; } };
        $service = new AiSuggestionService($gateway, $costs, $audit);
        $this->expectException(\InvalidArgumentException::class);
        $service->suggest(new AiSuggestionRequest('refund.order', [], new CommerceScope('store', 'channel', 'en', 'USD', 'tenant'), 'actor'));
    }

    public function testTenantScopeIsMandatory(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new AiSuggestionRequest('catalog.copy', [], new CommerceScope('store', 'channel', 'en', 'USD'), 'actor');
    }

    public function testBudgetIsReservedBeforeGateway(): void
    {
        $reserved = 0;
        $costs = new class($reserved) implements AiCostLedger {
            public function __construct(private int &$reserved) {}
            public function remainingMinor(string $tenantId): int { return 100; }
            public function reserveMinor(string $tenantId, int $amountMinor): bool { $this->reserved += $amountMinor; return true; }
        };
        $gateway = new class implements AiSuggestionGateway {
            public function suggest(AiSuggestionRequest $request): AiSuggestionResult { return new AiSuggestionResult('available'); }
        };
        $audit = new class implements AiAuditWriter { public function record(string $tenantId, string $actorId, string $task, string $status, array $metadata = []): void {} };
        $service = new AiSuggestionService($gateway, $costs, $audit);
        $service->suggest(new AiSuggestionRequest('catalog.copy', [], new CommerceScope('store', 'channel', 'en', 'USD', 'tenant'), 'actor', 25));
        self::assertSame(25, $reserved);
    }
}
