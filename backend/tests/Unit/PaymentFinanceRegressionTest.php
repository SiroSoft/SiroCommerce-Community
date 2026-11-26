<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\Finance\Application\CreditMemoManager;
use App\Modules\Finance\Contracts\CreditMemoStore;
use App\Modules\Finance\Contracts\InvoiceStore;
use App\Modules\Payment\Application\PaymentCommandService;
use App\Modules\Payment\Application\PaymentOperationService;
use App\Modules\Payment\Contracts\PaymentOperationStore;
use App\Modules\Payment\Contracts\PaymentRefundStore;
use App\Modules\Payment\Contracts\PaymentProvider;
use App\Modules\Payment\Contracts\PaymentStore;
use App\Modules\Payment\Domain\PaymentOperation;
use App\Modules\Queue\Contracts\Job;
use App\Modules\Queue\Contracts\JobStore;
use App\Modules\Webhook\Application\WebhookReceiver;
use App\Modules\Webhook\Contracts\WebhookEventStore;
use PHPUnit\Framework\TestCase;

final class PaymentFinanceRegressionTest extends TestCase
{
    public function testPartialCapturesAccumulateAndRefundsCannotExceedCapturedAmount(): void
    {
        $payment = ['id' => 'pay-partial', 'state' => 'authorized', 'amount_minor' => 1000, 'captured_amount_minor' => 0, 'currency' => 'VND'];
        $payments = $this->createMock(PaymentStore::class);
        $payments->method('find')->willReturnCallback(function (string $id) use (&$payment): ?array { return $id === 'pay-partial' ? $payment : null; });
        $payments->method('setState')->willReturnCallback(static function (string $id, string $state) use (&$payment): void { $payment['state'] = $state; });
        $payments->method('setCapturedAmount')->willReturnCallback(static function (string $id, int $amount) use (&$payment): void { $payment['captured_amount_minor'] = $amount; });
        $operations = new class implements PaymentOperationStore, PaymentRefundStore {
            /** @var array<string, array<string, mixed>> */
            public array $rows = [];
            public function findByIdempotency(string $key): ?array { return $this->rows[$key] ?? null; }
            public function create(array $operation): void { $this->rows[$operation['idempotency_key']] = $operation; }
            public function complete(string $id, array $result): void { foreach ($this->rows as &$row) if ($row['id'] === $id) foreach ($result as $key => $value) $row[$key] = $value; }
            public function refundedAmount(string $paymentId): int { $total = 0; foreach ($this->rows as $row) if ($row['payment_id'] === $paymentId && ($row['state'] ?? '') === 'refunded') $total += (int) $row['amount_minor']; return $total; }
        };
        $provider = new class implements PaymentProvider {
            public function execute(PaymentOperation $operation, array $context): array { return ['state' => $operation->type === 'refund' ? 'refunded' : 'succeeded']; }
            public function verifyWebhook(string $rawBody, array $headers): bool { return false; }
        };
        $service = new PaymentCommandService($payments, new PaymentOperationService($operations), $provider);

        $service->execute('pay-partial', 'capture', 400, 'VND', 'capture-1');
        $service->execute('pay-partial', 'capture', 300, 'VND', 'capture-2');
        self::assertSame(700, $payment['captured_amount_minor']);
        $service->execute('pay-partial', 'refund', 700, 'VND', 'refund-1');

        $this->expectException(\InvalidArgumentException::class);
        $service->execute('pay-partial', 'refund', 1, 'VND', 'refund-2');
    }

    public function testUnknownRefundDoesNotIssueCreditMemo(): void
    {
        $operations = new class implements PaymentOperationStore {
            public array $rows = [];
            public function findByIdempotency(string $key): ?array { return null; }
            public function create(array $operation): void { $this->rows[$operation['id']] = $operation; }
            public function complete(string $id, array $result): void { $this->rows[$id] = $this->rows[$id] + $result; }
        };
        $payments = $this->createMock(PaymentStore::class);
        $payments->method('find')->willReturn(['id' => 'pay-1', 'state' => 'captured', 'amount_minor' => 1000]);
        $provider = new class implements PaymentProvider {
            public function execute(PaymentOperation $operation, array $context): array { return ['state' => 'unknown', 'error' => 'provider timeout']; }
            public function verifyWebhook(string $rawBody, array $headers): bool { return false; }
        };
        $memos = $this->createMock(CreditMemoStore::class);
        $memos->expects(self::once())->method('createRefund');
        $memos->expects(self::once())->method('setRefundState')->with(self::anything(), 'failed');
        $memos->expects(self::never())->method('create');
        $invoices = $this->createMock(InvoiceStore::class);
        $invoices->method('find')->willReturn(['id' => 'inv-1', 'state' => 'issued', 'amount_minor' => 1000, 'order_id' => 'order-1', 'payment_id' => 'pay-1', 'currency' => 'VND']);
        $invoices->method('creditedAmount')->willReturn(0);
        $manager = new CreditMemoManager($memos, $invoices, new PaymentCommandService($payments, new PaymentOperationService($operations), $provider));
        $this->expectException(\RuntimeException::class);
        $manager->create('inv-1', 500, 'test', 'refund-unknown');
    }

    public function testReceivedWebhookIsReenqueuedAfterInitialEnqueueFailure(): void
    {
        $events = $this->createMock(WebhookEventStore::class);
        $state = ['state' => 'received', 'payload_hash' => hash('sha256', 'body'), 'payload_json' => '{"type":"payment.captured"}'];
        $events->expects(self::never())->method('receive');
        $findCount = 0;
        $events->method('find')->willReturnCallback(static function () use (&$findCount, $state): ?array {
            return ++$findCount === 1 ? null : $state;
        });
        $events->expects(self::once())->method('markQueued');
        $jobs = $this->createMock(JobStore::class);
        $attempt = 0;
        $jobs->expects(self::exactly(2))->method('enqueue')->willReturnCallback(function (Job $job) use (&$attempt): void {
            if (++$attempt === 1) throw new \RuntimeException('queue unavailable');
        });
        $receiver = new WebhookReceiver($events, $jobs);
        try {
            $receiver->receive('stripe', 'evt-retry', 'body', true);
        } catch (\RuntimeException) {
        }
        self::assertTrue($receiver->receive('stripe', 'evt-retry', 'body', true)['duplicate']);
    }
}
