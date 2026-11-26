<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\Payment\Application\PaymentOperationService;
use App\Modules\Payment\Contracts\PaymentOperationStore;
use App\Modules\Payment\Contracts\PaymentProvider;
use App\Modules\Payment\Domain\PaymentOperation;
use App\Modules\Shared\Domain\Money;
use PHPUnit\Framework\TestCase;

final class PaymentOperationRaceTest extends TestCase
{
    public function testUniqueInsertRaceReturnsWinnerWithoutCallingProviderAgain(): void
    {
        $store = new class implements PaymentOperationStore {
            public function findByIdempotency(string $key): ?array { return $key === 'same-key' ? ['id' => 'winner', 'state' => 'succeeded'] : null; }
            public function create(array $operation): void { throw new \RuntimeException('unique constraint'); }
            public function complete(string $id, array $result): void {}
        };
        $provider = new class implements PaymentProvider {
            public int $calls = 0;
            public function execute(PaymentOperation $operation, array $context): array { $this->calls++; return ['state' => 'succeeded']; }
            public function verifyWebhook(string $rawBody, array $headers): bool { return false; }
        };

        $result = (new PaymentOperationService($store))->execute(
            new PaymentOperation('loser', 'payment-1', 'capture', new Money(100, 'VND'), 'same-key'),
            $provider,
        );

        self::assertTrue($result->ok);
        self::assertSame('winner', $result->value['id']);
        self::assertSame(0, $provider->calls);
    }

    public function testUnknownOperationIsNotReplayedThroughTheProvider(): void
    {
        $store = new class implements PaymentOperationStore {
            public function findByIdempotency(string $key): ?array { return ['id' => 'unknown', 'state' => 'unknown']; }
            public function create(array $operation): void { throw new \LogicException('create must not be called'); }
            public function complete(string $id, array $result): void { throw new \LogicException('complete must not be called'); }
        };
        $provider = new class implements PaymentProvider {
            public int $calls = 0;
            public function execute(PaymentOperation $operation, array $context): array { $this->calls++; return ['state' => 'succeeded']; }
            public function verifyWebhook(string $rawBody, array $headers): bool { return false; }
        };

        $result = (new PaymentOperationService($store))->execute(
            new PaymentOperation('retry', 'payment-1', 'refund', new Money(100, 'VND'), 'same-key'),
            $provider,
        );

        self::assertFalse($result->ok);
        self::assertSame('payment.operation_unknown', $result->code);
        self::assertSame(0, $provider->calls);
    }

    public function testProcessingOperationIsNotReportedAsSuccessful(): void
    {
        $store = new class implements PaymentOperationStore {
            public function findByIdempotency(string $key): ?array { return ['id' => 'processing', 'state' => 'processing']; }
            public function create(array $operation): void { throw new \LogicException('create must not be called'); }
            public function complete(string $id, array $result): void { throw new \LogicException('complete must not be called'); }
        };
        $provider = $this->createMock(PaymentProvider::class);
        $provider->expects(self::never())->method('execute');

        $result = (new PaymentOperationService($store))->execute(
            new PaymentOperation('retry', 'payment-1', 'capture', new Money(100, 'VND'), 'same-key'),
            $provider,
        );

        self::assertFalse($result->ok);
        self::assertSame('payment.operation_in_progress', $result->code);
    }
}
