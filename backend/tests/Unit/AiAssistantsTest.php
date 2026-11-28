<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Extensions\Siro\ShopperAssistant\Extension as ShopperExtension;
use App\Extensions\Siro\ShopperAssistant\ShopperAssistantService;
use App\Extensions\Siro\StaffAssistant\Extension as StaffExtension;
use App\Extensions\Siro\StaffAssistant\StaffAssistantService;
use App\Modules\Ai\Application\AiSuggestionService;
use App\Modules\Ai\Application\AssistantService;
use App\Modules\Ai\Contracts\AiAuditWriter;
use App\Modules\Ai\Contracts\AiCostLedger;
use App\Modules\Ai\Contracts\AiSuggestionGateway;
use App\Modules\Ai\Contracts\AiSuggestionRequest;
use App\Modules\Ai\Contracts\AiSuggestionResult;
use App\Support\CommerceScope;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/app/Extensions/Siro/StaffAssistant/Extension.php';
require_once dirname(__DIR__, 2) . '/app/Extensions/Siro/ShopperAssistant/Extension.php';

final class AiAssistantsTest extends TestCase
{
    private function assistant(): AssistantService
    {
        $gateway = new class implements AiSuggestionGateway {
            public function suggest(AiSuggestionRequest $request): AiSuggestionResult
            {
                return new AiSuggestionResult('available', ['answer' => 'Only facts from the supplied context are used.']);
            }
        };
        $costs = new class implements AiCostLedger {
            public function remainingMinor(string $tenantId): int { return 100; }
            public function reserveMinor(string $tenantId, int $amountMinor): bool { return true; }
        };
        $audit = new class implements AiAuditWriter {
            public function record(string $tenantId, string $actorId, string $task, string $status, array $metadata = []): void {}
        };

        return new AssistantService(new AiSuggestionService($gateway, $costs, $audit));
    }

    private function scope(): CommerceScope
    {
        return new CommerceScope('store-1', 'web', 'en', 'USD', 'tenant-1');
    }

    public function testBothExtensionsDeclareReadOnlyCompatibilityAndSlots(): void
    {
        $staff = (new StaffExtension())->manifest();
        $shopper = (new ShopperExtension())->manifest();

        self::assertSame('siro/staff-assistant', $staff['id']);
        self::assertSame('^0.3', $staff['requires']['commerce']);
        self::assertSame([], $staff['slots']['storefront']);
        self::assertSame('assistant', $staff['admin']['panels'][0]['type']);

        self::assertSame('siro/shopper-assistant', $shopper['id']);
        self::assertSame('^0.3', $shopper['requires']['commerce']);
        self::assertSame(['header'], $shopper['slots']['storefront']);
        self::assertArrayNotHasKey('admin', $shopper);
    }

    public function testStaffAssistantAnswersWithoutMutatingAnything(): void
    {
        $result = (new StaffAssistantService($this->assistant()))->ask(
            $this->scope(),
            ['question' => 'Summarize the current order status.'],
            'staff-7',
        );

        self::assertSame('available', $result['status']);
        self::assertSame('Only facts from the supplied context are used.', $result['answer']);
        self::assertTrue($result['read_only']);
        self::assertFalse($result['autonomous_mutation']);
    }

    public function testShopperAssistantAnswersWithoutReadingOrders(): void
    {
        $result = (new ShopperAssistantService($this->assistant()))->ask(
            $this->scope(),
            ['question' => 'Can you help me choose a size?'],
        );

        self::assertSame('available', $result['status']);
        self::assertSame('Only facts from the supplied context are used.', $result['answer']);
        self::assertSame([], $result['sources']);
        self::assertFalse($result['autonomous_mutation']);
    }

    public function testShortQuestionsAreRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new ShopperAssistantService($this->assistant()))->ask($this->scope(), ['question' => 'x']);
    }
}
