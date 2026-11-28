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

final class AssistantMultilingualTest extends TestCase
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

    /**
     * @param callable(AiSuggestionRequest): AiSuggestionResult $handler
     * @return array{0: AssistantService, 1: \ArrayObject<int, AiSuggestionRequest>}
     */
    private function recording(callable $handler): array
    {
        $calls = new \ArrayObject();
        $gateway = new class($handler, $calls) implements AiSuggestionGateway {
            public function __construct(
                private readonly mixed $handler,
                private readonly \ArrayObject $calls,
            ) {
            }
            public function suggest(AiSuggestionRequest $request): AiSuggestionResult
            {
                $this->calls->append($request);
                return ($this->handler)($request);
            }
        };

        return [$this->assistant($gateway), $calls];
    }

    public function testEnglishPassthroughDoesNotCallTranslation(): void
    {
        [$assistant, $calls] = $this->recording(static fn (AiSuggestionRequest $request): AiSuggestionResult => new AiSuggestionResult('available', ['answer' => 'Grounded English answer']));

        $result = $assistant->ask(
            new CommerceScope('store-1', 'web', 'en', 'USD', 'tenant-1'),
            'staff',
            'Summarize this order.',
            ['sources' => [['label' => 'Order', 'url' => '/orders/1']]],
            'staff-1',
        );

        self::assertSame('available', $result['status']);
        self::assertSame('Grounded English answer', $result['answer']);
        self::assertSame([['label' => 'Order', 'url' => '/orders/1']], $result['sources']);
        self::assertCount(1, $calls);
        self::assertSame('assistant.staff', $calls[0]->task);
        self::assertSame('Summarize this order.', $calls[0]->input['question']);
    }

    public function testVietnameseQuestionUsesEnglishPivotAndTranslatesAnswerBack(): void
    {
        $handler = static function (AiSuggestionRequest $request): AiSuggestionResult {
            return match ($request->task) {
                AssistantService::TRANSLATE_IN_TASK => new AiSuggestionResult('available', ['text' => 'Is this shirt still available?']),
                'assistant.shopper' => new AiSuggestionResult('available', ['answer' => 'Yes, it is still available.']),
                AssistantService::TRANSLATE_OUT_TASK => new AiSuggestionResult('available', ['text' => 'Vâng, sản phẩm vẫn còn hàng.']),
                default => new AiSuggestionResult('provider_unavailable'),
            };
        };
        [$assistant, $calls] = $this->recording($handler);

        $result = $assistant->ask(
            new CommerceScope('store-1', 'web', 'vi', 'VND', 'tenant-1'),
            'shopper',
            'Áo này còn không?',
            ['sources' => [['label' => 'Áo linen', 'url' => '/product/ao-linen']]],
            'guest',
        );

        self::assertSame('available', $result['status']);
        self::assertSame('Vâng, sản phẩm vẫn còn hàng.', $result['answer']);
        self::assertSame([['label' => 'Áo linen', 'url' => '/product/ao-linen']], $result['sources']);
        self::assertCount(3, $calls);
        self::assertSame(AssistantService::TRANSLATE_IN_TASK, $calls[0]->task);
        self::assertSame('Áo này còn không?', $calls[0]->input['text']);
        self::assertSame('vi', $calls[0]->input['from']);
        self::assertSame('en', $calls[0]->input['to']);
        self::assertSame('assistant.shopper', $calls[1]->task);
        self::assertSame('Is this shirt still available?', $calls[1]->input['question']);
        self::assertSame(AssistantService::TRANSLATE_OUT_TASK, $calls[2]->task);
        self::assertSame('Yes, it is still available.', $calls[2]->input['text']);
        self::assertSame('en', $calls[2]->input['from']);
        self::assertSame('vi', $calls[2]->input['to']);
    }

    public function testUnsupportedScopeLocaleFallsBackToEnglish(): void
    {
        [$assistant, $calls] = $this->recording(static fn (AiSuggestionRequest $request): AiSuggestionResult => new AiSuggestionResult('available', ['answer' => 'English answer']));

        $result = $assistant->ask(
            new CommerceScope('store-1', 'web', 'fr', 'EUR', 'tenant-1'),
            'staff',
            'Résumé de la commande.',
            [],
            'staff-1',
        );

        self::assertSame('English answer', $result['answer']);
        self::assertCount(1, $calls);
        self::assertSame('assistant.staff', $calls[0]->task);
        self::assertSame('Résumé de la commande.', $calls[0]->input['question']);
    }

    public function testConfiguredLanguageListExcludesLocaleAndFallsBackToEnglish(): void
    {
        $previous = $_ENV['ASSISTANT_LANGUAGES'] ?? null;
        $previousEnv = getenv('ASSISTANT_LANGUAGES');
        $_ENV['ASSISTANT_LANGUAGES'] = 'en,vi';
        putenv('ASSISTANT_LANGUAGES=en,vi');
        try {
            [$assistant, $calls] = $this->recording(static fn (AiSuggestionRequest $request): AiSuggestionResult => new AiSuggestionResult('available', ['answer' => 'English answer']));

            $result = $assistant->ask(
                new CommerceScope('store-1', 'web', 'ja', 'JPY', 'tenant-1'),
                'staff',
                '注文を要約してください。',
                [],
                'staff-1',
            );

            self::assertSame('English answer', $result['answer']);
            self::assertCount(1, $calls);
            self::assertSame('注文を要約してください。', $calls[0]->input['question']);
        } finally {
            if ($previous === null) unset($_ENV['ASSISTANT_LANGUAGES']);
            else $_ENV['ASSISTANT_LANGUAGES'] = $previous;
            $previousEnv === false ? putenv('ASSISTANT_LANGUAGES') : putenv('ASSISTANT_LANGUAGES=' . $previousEnv);
        }
    }

    public function testInputTranslationFailureFallsBackToOriginalQuestion(): void
    {
        $handler = static function (AiSuggestionRequest $request): AiSuggestionResult {
            return $request->task === AssistantService::TRANSLATE_IN_TASK
                ? new AiSuggestionResult('provider_unavailable', reason: 'Translation is not configured.')
                : new AiSuggestionResult('available', ['answer' => 'Grounded English answer']);
        };
        [$assistant, $calls] = $this->recording($handler);

        $result = $assistant->ask(
            new CommerceScope('store-1', 'web', 'vi', 'VND', 'tenant-1'),
            'staff',
            'Tóm tắt đơn hàng này.',
            [],
            'staff-1',
        );

        self::assertSame('available', $result['status']);
        self::assertSame('Grounded English answer', $result['answer']);
        self::assertCount(2, $calls);
        self::assertSame(AssistantService::TRANSLATE_IN_TASK, $calls[0]->task);
        self::assertSame('assistant.staff', $calls[1]->task);
        self::assertSame('Tóm tắt đơn hàng này.', $calls[1]->input['question']);
    }

    public function testEmailPhoneAndTokenAreRedactedBeforeTheGateway(): void
    {
        [$assistant, $calls] = $this->recording(static fn (AiSuggestionRequest $request): AiSuggestionResult => new AiSuggestionResult('available', ['answer' => 'Grounded answer']));

        $result = $assistant->ask(
            new CommerceScope('store-1', 'web', 'en', 'USD', 'tenant-1'),
            'staff',
            'Call +84 912 345 678 or email customer@example.com about token 0123456789abcdef0123456789abcdef.',
            [],
            'staff-1',
        );

        self::assertSame('available', $result['status']);
        self::assertStringNotContainsString('customer@example.com', (string) $calls[0]->input['question']);
        self::assertStringNotContainsString('+84 912 345 678', (string) $calls[0]->input['question']);
        self::assertStringNotContainsString('0123456789abcdef0123456789abcdef', (string) $calls[0]->input['question']);
        self::assertStringContainsString('[redacted-email]', (string) $calls[0]->input['question']);
        self::assertStringContainsString('[redacted-phone]', (string) $calls[0]->input['question']);
        self::assertStringContainsString('[redacted-token]', (string) $calls[0]->input['question']);
    }

    public function testOutputTranslationFailureFallsBackToEnglishAnswer(): void
    {
        $handler = static function (AiSuggestionRequest $request): AiSuggestionResult {
            return match ($request->task) {
                AssistantService::TRANSLATE_IN_TASK => new AiSuggestionResult('available', ['text' => 'Summarize this order.']),
                'assistant.staff' => new AiSuggestionResult('available', ['answer' => 'The order is pending.']),
                AssistantService::TRANSLATE_OUT_TASK => new AiSuggestionResult('provider_unavailable', reason: 'Translation is not configured.'),
                default => new AiSuggestionResult('provider_unavailable'),
            };
        };
        [$assistant, $calls] = $this->recording($handler);

        $result = $assistant->ask(
            new CommerceScope('store-1', 'web', 'vi', 'VND', 'tenant-1'),
            'staff',
            'Tóm tắt đơn hàng này.',
            [],
            'staff-1',
        );

        self::assertSame('available', $result['status']);
        self::assertSame('The order is pending.', $result['answer']);
        self::assertCount(3, $calls);
        self::assertSame(AssistantService::TRANSLATE_OUT_TASK, $calls[2]->task);
    }
}
