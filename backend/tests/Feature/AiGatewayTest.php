<?php
declare(strict_types=1);
namespace Tests\Feature;

use App\Modules\Ai\Application\AiSuggestionService;
use App\Modules\Ai\Contracts\AiSuggestionRequest;
use App\Modules\Ai\Infrastructure\DatabaseAiAuditWriter;
use App\Modules\Ai\Infrastructure\OllamaGateway;
use App\Modules\Ai\Infrastructure\OpenAiCompatibleGateway;
use App\Modules\Ai\Infrastructure\PersistentAiCostLedger;
use App\Modules\Ai\Infrastructure\UnavailableAiSuggestionGateway;
use App\Support\CommerceScope;
use Siro\Core\Database;
use Tests\TestCase;

/**
 * LLM gateway impls (spec LLM_GATEWAY_SPEC): provider selection, budget
 * ledger, audit rows, fail-closed transport errors. Fake transport closure,
 * no real HTTP.
 */
final class AiGatewayTest extends TestCase
{
    /** @var list<array{0: string, 1: array{real: string|false, env: mixed}}> */
    private array $swapped = [];

    protected function tearDown(): void
    {
        foreach (array_reverse($this->swapped) as [$key, $snapshot]) {
            $snapshot['real'] === false ? putenv($key) : putenv($key . '=' . $snapshot['real']);
            if ($snapshot['env'] === null) unset($_ENV[$key]);
            else $_ENV[$key] = $snapshot['env'];
        }
        $this->swapped = [];
        parent::tearDown();
    }

    private function env(string $key, string $value): void
    {
        $this->swapped[] = [$key, ['real' => getenv($key), 'env' => $_ENV[$key] ?? null]];
        putenv($key . '=' . $value);
        $_ENV[$key] = $value;
    }

    private function scope(): CommerceScope
    {
        return new CommerceScope('default', 'web', 'en', 'VND', 'tenant-ai-test');
    }

    private function service(object $gateway): AiSuggestionService
    {
        return new AiSuggestionService($gateway, new PersistentAiCostLedger(), new DatabaseAiAuditWriter());
    }

    /** @param array<string, mixed> $input */
    private function request(array $input = ['topic' => 'linen shirts']): AiSuggestionRequest
    {
        return new AiSuggestionRequest('product.copy_draft', $input, $this->scope(), 'actor-1', 100);
    }

    public function testOpenAiHappyPathReservesBudgetAndAudits(): void
    {
        $this->env('AI_BUDGET_MINOR', '1000');
        $seen = [];
        $transport = static function (string $url, string $body, array $headers) use (&$seen): \Siro\Core\HttpResponse {
            $seen = [$url, $body, $headers];
            self::assertStringEndsWith('/chat/completions', $url);
            self::assertArrayHasKey('Authorization', $headers);
            $payload = json_decode($body, true);
            self::assertSame('test-model', $payload['model']);
            return new \Siro\Core\HttpResponse(200, json_encode(['choices' => [['message' => ['content' => '{"headline":"Soft linen"}']]], 'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5]]), '');
        };
        $this->env('AI_BASE_URL', 'https://llm.example.test/v1');
        $this->env('AI_API_KEY', 'secret-key');
        $this->env('AI_MODEL', 'test-model');
        $result = $this->service(new OpenAiCompatibleGateway($transport))->suggest($this->request());
        self::assertSame('available', $result->status);
        self::assertSame('Soft linen', $result->suggestion['headline']);
        self::assertSame('openai', $result->usage['provider']);
        self::assertSame(900, (new PersistentAiCostLedger())->remainingMinor('tenant-ai-test'));
        $audit = Database::table('ai_audits')->where('tenant_id', 'tenant-ai-test')->first();
        self::assertIsArray($audit);
        self::assertSame('available', $audit['status']);
        // PII keys never leave the process.
        self::assertStringNotContainsString('secret-key', $seen[1]);
        $leak = $this->service(new OpenAiCompatibleGateway($transport))->suggest($this->request(['topic' => 'x', 'email' => 'buyer@example.test']));
        self::assertSame('available', $leak->status);
        self::assertStringNotContainsString('buyer@example.test', $seen[1]);
    }

    public function testZeroCostReservationRequiresFundedBudget(): void
    {
        $this->env('AI_BUDGET_MINOR', '1000');
        $ledger = new PersistentAiCostLedger();
        self::assertTrue($ledger->reserveMinor('tenant-zero-funded', 0));
        self::assertSame(1000, $ledger->remainingMinor('tenant-zero-funded'));

        $this->env('AI_BUDGET_MINOR', '0');
        self::assertFalse($ledger->reserveMinor('tenant-zero-unfunded', 0));
        self::assertSame(0, $ledger->remainingMinor('tenant-zero-unfunded'));
    }

    public function testOllamaHappyPathWithoutKey(): void
    {
        $this->env('AI_BUDGET_MINOR', '500');
        $transport = static fn (): \Siro\Core\HttpResponse => new \Siro\Core\HttpResponse(200, json_encode(['message' => ['content' => 'Just some advice'], 'prompt_eval_count' => 7, 'eval_count' => 9]), '');
        $this->env('AI_OLLAMA_URL', 'http://localhost:11434');
        $this->env('AI_OLLAMA_MODEL', 'qwen2.5:7b');
        $result = $this->service(new OllamaGateway($transport))->suggest($this->request());
        self::assertSame('available', $result->status);
        self::assertSame('Just some advice', $result->suggestion['text']);
        self::assertSame('ollama', $result->usage['provider']);
        self::assertSame(7, $result->usage['prompt_tokens']);
    }

    public function testTransportFailureAndNon2xxMapToUnavailable(): void
    {
        $this->env('AI_BUDGET_MINOR', '500');
        $this->env('AI_BASE_URL', 'https://llm.example.test/v1');
        $this->env('AI_API_KEY', 'k');
        $this->env('AI_MODEL', 'm');
        $throwing = static function (): never { throw new \RuntimeException('connection refused'); };
        $failed = $this->service(new OpenAiCompatibleGateway($throwing))->suggest($this->request());
        self::assertSame('provider_unavailable', $failed->status);
        self::assertStringNotContainsString('refused', (string) ($failed->reason ?? ''));
        $bad = static fn (): \Siro\Core\HttpResponse => new \Siro\Core\HttpResponse(500, 'boom', '');
        $non2xx = $this->service(new OpenAiCompatibleGateway($bad))->suggest($this->request());
        self::assertSame('provider_unavailable', $non2xx->status);
        $empty = (new OpenAiCompatibleGateway(static fn (): \Siro\Core\HttpResponse => new \Siro\Core\HttpResponse(200, '{}', '')))->suggest($this->request());
        self::assertSame('provider_unavailable', $empty->status);
    }

    public function testUnconfiguredProviderAndEmptyBudgetStayClosed(): void
    {
        $this->env('AI_BUDGET_MINOR', '0');
        $this->env('AI_BASE_URL', '');
        $this->env('AI_API_KEY', '');
        $this->env('AI_MODEL', '');
        $denied = (new UnavailableAiSuggestionGateway())->suggest($this->request());
        self::assertSame('provider_unavailable', $denied->status);
        $noBudget = $this->service(new OpenAiCompatibleGateway(static fn (): \Siro\Core\HttpResponse => new \Siro\Core\HttpResponse(200, '{}', '')))->suggest($this->request());
        self::assertSame('budget_exceeded', $noBudget->status);
    }

    public function testMutationTaskIsDeniedBeforeAnySpend(): void
    {
        $this->env('AI_BUDGET_MINOR', '500');
        $request = new AiSuggestionRequest('order.refund_now', ['order_id' => 'o-1'], $this->scope(), 'actor-1', 100);
        try {
            $this->service(new UnavailableAiSuggestionGateway())->suggest($request);
            self::fail('mutation task must throw');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('mutation', strtolower($e->getMessage()));
        }
        self::assertSame(500, (new PersistentAiCostLedger())->remainingMinor('tenant-ai-test'));
    }
}
