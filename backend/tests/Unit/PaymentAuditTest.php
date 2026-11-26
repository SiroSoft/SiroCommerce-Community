<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\Payment\Application\PaymentCommandService;
use App\Modules\Payment\Application\PaymentOperationService;
use App\Modules\Payment\Contracts\PaymentOperationStore;
use App\Modules\Payment\Contracts\PaymentProvider;
use App\Modules\Payment\Contracts\PaymentStore;
use App\Modules\Payment\Domain\PaymentOperation;
use App\Modules\Shared\Contracts\AuditWriter;
use App\Modules\Shared\Application\CommandContext;
use PHPUnit\Framework\TestCase;

final class PaymentAuditTest extends TestCase
{
    public function testSuccessfulMutationRecordsSafeActorAndCorrelationContext(): void
    {
        $payment = ['id' => 'pay-audit', 'state' => 'authorized', 'amount_minor' => 1000, 'captured_amount_minor' => 0, 'currency' => 'VND', 'provider' => 'stripe', 'secret_key' => 'must-not-leak'];
        $payments = $this->createMock(PaymentStore::class);
        $payments->method('find')->willReturnCallback(static function (string $id) use (&$payment): ?array { return $id === 'pay-audit' ? $payment : null; });
        $payments->method('setState')->willReturnCallback(static function (string $id, string $state) use (&$payment): void { $payment['state'] = $state; });
        $operations = $this->operations();
        $audit = $this->createMock(AuditWriter::class);
        $audit->expects(self::once())->method('record')->with(
            self::callback(static fn (CommandContext $context): bool => $context->actor === 'finance@example.test' && $context->correlationId === 'cor-1' && $context->causationId === 'cause-1'),
            'payment.void',
            'payment',
            'pay-audit',
            self::callback(static fn (array $before): bool => !array_key_exists('secret_key', $before)),
            self::callback(static fn (array $after): bool => $after['state'] === 'voided' && !array_key_exists('secret_key', $after)),
            'payment mutation completed',
        );

        $service = new PaymentCommandService($payments, new PaymentOperationService($operations), $this->provider(), $audit);
        $service->execute('pay-audit', 'void', 1000, 'VND', 'void-1', ['actor' => 'finance@example.test', 'correlation_id' => 'cor-1', 'causation_id' => 'cause-1']);
    }

    public function testProviderFailureDoesNotRecordCompletedMutationAudit(): void
    {
        $payments = $this->createMock(PaymentStore::class);
        $payments->method('find')->willReturn(['id' => 'pay-fail', 'state' => 'authorized', 'amount_minor' => 1000, 'captured_amount_minor' => 0, 'currency' => 'VND']);
        $payments->expects(self::never())->method('setState');
        $audit = $this->createMock(AuditWriter::class);
        $audit->expects(self::never())->method('record');
        $provider = $this->createMock(PaymentProvider::class);
        $provider->method('execute')->willReturn(['state' => 'failed', 'error' => 'provider rejected', 'api_key' => 'secret']);
        $provider->method('verifyWebhook')->willReturn(false);

        $this->expectException(\RuntimeException::class);
        (new PaymentCommandService($payments, new PaymentOperationService($this->operations()), $provider, $audit))->execute('pay-fail', 'void', 1000, 'VND', 'void-fail');
    }

    public function testCompletedReplayDoesNotApplyPaymentMutationAgain(): void
    {
        $payment = ['id' => 'pay-replay', 'state' => 'authorized', 'amount_minor' => 1000, 'captured_amount_minor' => 0, 'currency' => 'VND'];
        $payments = $this->createMock(PaymentStore::class);
        $payments->method('find')->willReturn($payment);
        $payments->expects(self::never())->method('setState');
        $payments->expects(self::never())->method('setCapturedAmount');
        $operations = new class implements PaymentOperationStore {
            public function findByIdempotency(string $key): ?array
            { return ['id' => 'op-1', 'payment_id' => 'pay-replay', 'type' => 'capture', 'amount_minor' => 1000, 'currency' => 'VND', 'state' => 'succeeded']; }
            public function create(array $operation): void {}
            public function complete(string $id, array $result): void {}
        };
        $provider = $this->createMock(PaymentProvider::class);
        $provider->expects(self::never())->method('execute');

        $result = (new PaymentCommandService($payments, new PaymentOperationService($operations), $provider))->execute('pay-replay', 'capture', 1000, 'VND', 'capture-1');

        self::assertSame($payment, $result);
    }

    private function provider(): PaymentProvider
    {
        $provider = $this->createMock(PaymentProvider::class);
        $provider->method('execute')->willReturn(['state' => 'succeeded', 'provider_ref' => 'ref-1', 'secret_key' => 'secret']);
        $provider->method('verifyWebhook')->willReturn(false);
        return $provider;
    }

    private function operations(): PaymentOperationStore
    {
        return new class implements PaymentOperationStore {
            /** @var array<string, array<string, mixed>> */
            private array $rows = [];
            public function findByIdempotency(string $key): ?array { return $this->rows[$key] ?? null; }
            public function create(array $operation): void { $this->rows[$operation['idempotency_key']] = $operation; }
            public function complete(string $id, array $result): void { foreach ($this->rows as &$row) if ($row['id'] === $id) $row += $result; }
        };
    }
}
