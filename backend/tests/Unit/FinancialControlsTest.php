<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\Payment\Application\PaymentCommandService;
use App\Modules\Payment\Application\PaymentOperationService;
use App\Modules\Payment\Contracts\PaymentOperationStore;
use App\Modules\Payment\Contracts\PaymentProvider;
use App\Modules\Payment\Contracts\PaymentStore;
use App\Modules\Payment\Domain\PaymentOperation;
use App\Modules\Platform\Infrastructure\DefaultTaxProvider;
use App\Modules\Shared\Domain\Currency;
use PHPUnit\Framework\TestCase;

final class FinancialControlsTest extends TestCase
{
    public function testTaxEvidenceIsDeterministicAndCurrencyIsExplicit(): void
    {
        $provider = new DefaultTaxProvider();
        $first = $provider->calculate(['currency' => 'USD'], [['variant_id' => 'v-1']]);
        $second = $provider->calculate(['currency' => 'USD'], [['variant_id' => 'v-1']]);

        self::assertSame('USD', $first['currency']);
        self::assertSame($first['evidence_hash'], $second['evidence_hash']);
        self::assertSame(2, Currency::minorUnit('USD'));
        $this->expectException(\InvalidArgumentException::class);
        Currency::normalize('ZZZ');
    }

    public function testPaymentCurrencyMismatchFailsBeforeProviderExecution(): void
    {
        $operations = new class implements PaymentOperationStore {
            public function findByIdempotency(string $key): ?array { return null; }
            public function create(array $operation): void { }
            public function complete(string $id, array $result): void { }
        };
        $payments = $this->createMock(PaymentStore::class);
        $payments->method('find')->willReturn(['id' => 'pay-1', 'state' => 'authorized', 'amount_minor' => 100, 'currency' => 'USD']);
        $provider = $this->createMock(PaymentProvider::class);
        $provider->expects(self::never())->method('execute');

        $service = new PaymentCommandService($payments, new PaymentOperationService($operations), $provider);
        $this->expectException(\InvalidArgumentException::class);
        $service->execute('pay-1', 'capture', 100, 'VND', 'currency-mismatch');
    }
}
