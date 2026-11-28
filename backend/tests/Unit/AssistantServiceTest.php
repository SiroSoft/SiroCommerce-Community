<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\Ai\Application\AiSuggestionService;
use App\Modules\Ai\Application\AssistantService;
use App\Modules\Ai\Contracts\AiAuditWriter;
use App\Modules\Ai\Contracts\AiCostLedger;
use App\Modules\Ai\Contracts\AiSuggestionGateway;
use App\Modules\Ai\Contracts\AiSuggestionRequest;
use App\Modules\Ai\Contracts\AiSuggestionResult;
use App\Support\CommerceScope;
use PHPUnit\Framework\TestCase;

final class AssistantServiceTest extends TestCase
{
    private function assistant(AiSuggestionGateway $gateway): AssistantService
    {
        $costs = new class implements AiCostLedger {
            public function remainingMinor(string $tenantId): int { return 100; }
            public function reserveMinor(string $tenantId, int $amountMinor): bool { return true; }
        };
        $audit = new class implements AiAuditWriter {
            public function record(string $tenantId, string $actorId, string $task, string $status, array $metadata = []): void {}
        };

        return new AssistantService(new AiSuggestionService($gateway, $costs, $audit));
    }

    private function gateway(?array $suggestion = null, string $status = 'available'): AiSuggestionGateway
    {
        return new class($suggestion, $status) implements AiSuggestionGateway {
            /** @param array<string, mixed>|null $suggestion */
            public function __construct(private readonly ?array $suggestion, private readonly string $status) {}
            public function suggest(AiSuggestionRequest $request): AiSuggestionResult
            {
                return $this->status === 'available'
                    ? new AiSuggestionResult('available', $this->suggestion ?? ['answer' => 'Grounded answer'])
                    : new AiSuggestionResult($this->status, reason: 'Provider is not configured.');
            }
        };
    }

    public function testShopperUsesStoreTenantWhenTenantIsMissing(): void
    {
        $captured = null;
        $gateway = new class($captured) implements AiSuggestionGateway {
            public function __construct(private mixed &$captured) {}
            public function suggest(AiSuggestionRequest $request): AiSuggestionResult
            {
                $this->captured = $request;
                return new AiSuggestionResult('available', ['answer' => 'This shirt is available in the catalog.']);
            }
        };
        $result = $this->assistant($gateway)->ask(
            new CommerceScope('store-1', 'web', 'en', 'VND'),
            'shopper',
            'Áo này còn không?',
            ['product' => ['name' => 'Áo linen']],
            'guest',
        );

        self::assertSame('available', $result['status']);
        self::assertSame('This shirt is available in the catalog.', $result['answer']);
        self::assertTrue($result['read_only']);
        self::assertFalse($result['autonomous_mutation']);
        self::assertInstanceOf(AiSuggestionRequest::class, $captured);
        self::assertSame('store-1', $captured->scope->tenantId);
        self::assertSame('assistant.shopper', $captured->task);
    }

    public function testSourcesAreLimitedToSafeRelativeOrHttpsLinks(): void
    {
        $result = $this->assistant($this->gateway())->ask(
            new CommerceScope('store-1', 'web', 'en', 'USD', 'tenant-1'),
            'staff',
            'Summarize the order status.',
            ['sources' => [
                ['label' => 'Order', 'url' => '/orders/123'],
                ['label' => 'Product', 'url' => 'https://example.test/product/x'],
                ['label' => 'Bad', 'url' => 'javascript:alert(1)'],
                ['label' => 'Missing URL'],
            ]],
            'staff-1',
        );

        self::assertSame([
            ['label' => 'Order', 'url' => '/orders/123'],
            ['label' => 'Product', 'url' => 'https://example.test/product/x'],
        ], $result['sources']);
    }

    public function testStructuredSummaryIsUsedAsTheAnswer(): void
    {
        $result = $this->assistant($this->gateway(['summary' => 'Order 1001 is paid.']))->ask(
            new CommerceScope('store-1', 'web', 'en', 'USD', 'tenant-1'),
            'staff',
            'Summarize this order.',
            [],
            'staff-1',
        );

        self::assertSame('available', $result['status']);
        self::assertSame('Order 1001 is paid.', $result['answer']);
    }

    public function testUnknownStructuredShapeFallsBackToGroundedScalars(): void
    {
        $result = $this->assistant($this->gateway(['order_state_summary' => ['number' => '1001', 'state' => 'paid']]))->ask(
            new CommerceScope('store-1', 'web', 'en', 'USD', 'tenant-1'),
            'staff',
            'Summarize this order.',
            [],
            'staff-1',
        );

        self::assertSame('available', $result['status']);
        self::assertSame('number: 1001; state: paid', $result['answer']);
    }

    public function testProviderUnavailableIsReturnedWithoutInventingAnAnswer(): void
    {
        $result = $this->assistant($this->gateway(status: 'provider_unavailable'))->ask(
            new CommerceScope('store-1', 'web', 'en', 'USD', 'tenant-1'),
            'shopper',
            'What is your return policy?',
            [],
            'guest',
        );

        self::assertSame('provider_unavailable', $result['status']);
        self::assertNull($result['answer']);
        self::assertSame('Provider is not configured.', $result['reason']);
        self::assertFalse($result['autonomous_mutation']);
    }

    public function testQuestionAndContextBoundsAreEnforced(): void
    {
        $service = $this->assistant($this->gateway());
        $scope = new CommerceScope('store-1', 'web', 'en', 'USD', 'tenant-1');

        try {
            $service->ask($scope, 'shopper', 'x', [], 'guest');
            self::fail('short questions must be rejected');
        } catch (\InvalidArgumentException $exception) {
            self::assertStringContainsString('between 3 and 1000', $exception->getMessage());
        }

        try {
            $service->ask($scope, 'staff', 'Summarize this.', ['blob' => str_repeat('x', AssistantService::MAX_CONTEXT_BYTES)], 'staff-1');
            self::fail('oversized context must be rejected');
        } catch (\InvalidArgumentException $exception) {
            self::assertStringContainsString('too large', $exception->getMessage());
        }

        $this->expectException(\InvalidArgumentException::class);
        $service->ask($scope, 'other', 'Summarize this.', [], 'staff-1');
    }
}
