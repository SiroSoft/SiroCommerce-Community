<?php
declare(strict_types=1);
namespace Tests\Feature;

use App\Modules\Ai\Application\AiCopilotService;
use App\Modules\Ai\Application\AiSuggestionService;
use App\Modules\Ai\Contracts\AiAuditWriter;
use App\Modules\Ai\Contracts\AiCostLedger;
use App\Modules\Ai\Contracts\AiSuggestionGateway;
use App\Modules\Ai\Contracts\AiSuggestionRequest;
use App\Modules\Ai\Contracts\AiSuggestionResult;
use App\Support\CommerceScope;
use Siro\Core\Database;
use Tests\TestCase;

/**
 * Merchant copilot review queue (spec LLM_GATEWAY_SPEC §copilots): drafts land
 * pending, approval/rejection are explicit and one-shot, failures are kept
 * with a reason, no suggestion is applied automatically.
 */
final class AiCopilotTest extends TestCase
{
    private function service(AiSuggestionGateway $gateway): AiCopilotService
    {
        $costs = new class implements AiCostLedger {
            public function remainingMinor(string $tenantId): int { return 1000; }
            public function reserveMinor(string $tenantId, int $amountMinor): bool { return true; }
        };
        $audit = new class implements AiAuditWriter {
            public function record(string $tenantId, string $actorId, string $task, string $status, array $metadata = []): void {}
        };
        return new AiCopilotService(new AiSuggestionService($gateway, $costs, $audit));
    }

    private function gateway(string $status = 'available'): AiSuggestionGateway
    {
        return new class($status) implements AiSuggestionGateway {
            public function __construct(private readonly string $status) {}
            public function suggest(AiSuggestionRequest $request): AiSuggestionResult
            {
                if ($this->status !== 'available') return new AiSuggestionResult($this->status, reason: 'Provider not configured.');
                return new AiSuggestionResult('available', ['title' => 'Draft title', 'body' => 'Draft copy'], ['provider' => 'ollama', 'model' => 'qwen']);
            }
        };
    }

    private function scope(): CommerceScope
    {
        return new CommerceScope('default', 'web', 'en', 'VND', 'tenant-copilot');
    }

    public function testDraftLandsPendingThenApprovesOnce(): void
    {
        $service = $this->service($this->gateway());
        $row = $service->enqueue('product_description', ['name' => 'Linen shirt'], $this->scope(), 'actor-1', 'p-1');
        self::assertSame('pending', $row['state']);
        self::assertSame('Draft title', $row['suggestion']['title']);
        self::assertSame('ollama', $row['provider']);
        self::assertSame('p-1', $row['product_id']);
        self::assertSame(1, count($service->list('pending')));
        $approved = $service->approve($row['id'], 'reviewer-1');
        self::assertIsArray($approved);
        self::assertSame('approved', $approved['state']);
        self::assertSame('reviewer-1', $approved['reviewed_by']);
        // One-shot: a second decision on the same row is refused.
        self::assertNull($service->reject($row['id'], 'reviewer-2'));
        self::assertNull($service->approve($row['id'], 'reviewer-2'));
        self::assertSame([], $service->list('pending'));
    }

    public function testRejectAndFailurePaths(): void
    {
        $ok = $this->service($this->gateway());
        $row = $ok->enqueue('review_draft', ['rating' => 5], $this->scope(), 'actor-1');
        $rejected = $ok->reject($row['id'], 'reviewer-1');
        self::assertIsArray($rejected);
        self::assertSame('rejected', $rejected['state']);

        $failed = $this->service($this->gateway('provider_unavailable'));
        $row = $failed->enqueue('catalog_enrichment', ['product_id' => 'p-2'], $this->scope(), 'actor-1', 'p-2');
        self::assertSame('failed', $row['state']);
        self::assertNull($row['suggestion']);
        self::assertStringContainsString('Provider not configured', (string) $row['reason']);
        self::assertNull($failed->approve($row['id'], 'reviewer-1'));
    }

    public function testValidationAndTenantRequirement(): void
    {
        $service = $this->service($this->gateway());
        try {
            $service->enqueue('refund_order', [], $this->scope(), 'actor-1');
            self::fail('unsupported task must throw');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('Unsupported copilot task', $e->getMessage());
        }
        try {
            $service->enqueue('product_description', [], new CommerceScope('default', 'web', 'en', 'VND'), 'actor-1');
            self::fail('tenant must be required');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('tenant', $e->getMessage());
        }
        self::assertSame(0, (int) Database::connection()->query('SELECT COUNT(*) FROM ai_review_queue')->fetchColumn());
    }
}
