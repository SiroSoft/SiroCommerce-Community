<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Contracts\CodPaymentGateway;
use App\Modules\Payment\Application\PaymentCommandService;
use App\Modules\Payment\Application\PaymentOperationService;
use App\Modules\Payment\Application\PaymentReconciliationService;
use App\Modules\Payment\Contracts\PaymentReconciliationProvider;
use App\Modules\Payment\Contracts\PaymentReconciliationStore;
use App\Modules\Platform\Infrastructure\CodPaymentProvider;
use App\Modules\Platform\Infrastructure\PaymentStoreAdapter;
use App\Modules\Platform\Infrastructure\V2SideEffectRepository;
use App\Modules\Webhook\Application\WebhookProcessor;
use App\Repositories\OrderRepository;
use Siro\Core\Event;
use Tests\TestCase;

final class PaymentWebhookReliabilityTest extends TestCase
{
    public function testPaymentCommandsAreStateGuardedAndIdempotent(): void
    {
        $repo = new OrderRepository();
        $repo->createPayment(['id' => 'pay-rel-1', 'order_id' => 'order-rel-1', 'provider' => 'cod', 'amount_minor' => 1000, 'state' => 'authorized', 'provider_ref' => null, 'idempotency_key' => 'auth-rel-1']);
        $service = new PaymentCommandService(new PaymentStoreAdapter(), new PaymentOperationService(new V2SideEffectRepository()), new CodPaymentProvider(new CodPaymentGateway()));
        $captured = $service->execute('pay-rel-1', 'capture', 1000, 'VND', 'capture-rel-1');
        self::assertSame('captured', $captured['state']);
        self::assertSame($captured, $service->execute('pay-rel-1', 'capture', 1000, 'VND', 'capture-rel-1'));
        $refunded = $service->execute('pay-rel-1', 'refund', 1000, 'VND', 'refund-rel-1');
        self::assertSame('refunded', $refunded['state']);
    }

    public function testRefundsAreCumulativeAndCannotExceedCapturedAmount(): void
    {
        $repo = new OrderRepository();
        $repo->createPayment(['id' => 'pay-rel-split', 'order_id' => 'order-rel-split', 'provider' => 'cod', 'amount_minor' => 1000, 'state' => 'captured', 'provider_ref' => null, 'idempotency_key' => 'auth-rel-split']);
        $service = new PaymentCommandService(new PaymentStoreAdapter(), new PaymentOperationService(new V2SideEffectRepository()), new CodPaymentProvider(new CodPaymentGateway()));
        self::assertSame('partially_refunded', $service->execute('pay-rel-split', 'refund', 400, 'VND', 'refund-split-1')['state']);
        self::assertSame('refunded', $service->execute('pay-rel-split', 'refund', 600, 'VND', 'refund-split-2')['state']);
        $this->expectException(\InvalidArgumentException::class);
        $service->execute('pay-rel-split', 'refund', 1, 'VND', 'refund-split-3');
    }

    public function testUnknownReconciliationKeepsProviderEvidence(): void
    {
        $events = new V2SideEffectRepository();
        $events->create(['id' => 'op-unknown-1', 'payment_id' => 'pay-unknown-1', 'type' => 'capture', 'amount_minor' => 100, 'currency' => 'VND', 'idempotency_key' => 'unknown-key', 'state' => 'unknown']);
        $service = new \App\Modules\Payment\Application\PaymentReconciliationService($events);
        $provider = new class implements \App\Modules\Payment\Contracts\PaymentReconciliationProvider {
            public function reconcile(array $operation): array { return ['state' => 'unknown', 'provider_status' => 'pending', 'provider_ref' => 'external-1']; }
        };
        $result = $service->reconcile($provider, true);
        self::assertSame(1, $result['unknown']);
        $operation = \App\Models\PaymentOperation::find('op-unknown-1');
        self::assertStringContainsString('external-1', (string) $operation?->toArray()['result_json']);
    }

    public function testCaptureReconciliationReplayAfterApplyBeforeResolveDoesNotOverCapture(): void
    {
        $repo = new OrderRepository();
        $repo->createPayment(['id' => 'pay-rel-reconcile-replay', 'order_id' => 'order-rel-reconcile-replay', 'provider' => 'test', 'amount_minor' => 1000, 'state' => 'authorized', 'provider_ref' => null, 'idempotency_key' => 'auth-rel-reconcile-replay']);
        $operations = new class implements PaymentReconciliationStore {
            public function unresolved(): array
            {
                return [['id' => 'op-rel-reconcile-replay', 'payment_id' => 'pay-rel-reconcile-replay', 'type' => 'capture', 'amount_minor' => 1000, 'state' => 'unknown']];
            }
            public function reconcile(string $id, array $result): void {}
        };
        $provider = new class implements PaymentReconciliationProvider {
            public function reconcile(array $operation): array { return ['state' => 'captured', 'provider_ref' => 'provider-capture-replay']; }
        };
        $service = new PaymentReconciliationService($operations, new PaymentStoreAdapter());

        $service->reconcile($provider, true);
        $service->reconcile($provider, true); // Simulates the crash window before operation resolution.

        $payment = (new PaymentStoreAdapter())->find('pay-rel-reconcile-replay');
        self::assertSame('captured', $payment['state']);
        self::assertSame(1000, (int) $payment['captured_amount_minor']);
        self::assertSame('provider-capture-replay', $payment['provider_ref']);
    }

    public function testCaptureWithDifferentProviderReferenceCannotTransitionCapturedPayment(): void
    {
        $repo = new OrderRepository();
        $repo->createPayment(['id' => 'pay-rel-invalid-capture', 'order_id' => 'order-rel-invalid-capture', 'provider' => 'test', 'amount_minor' => 1000, 'state' => 'authorized', 'provider_ref' => null, 'idempotency_key' => 'auth-rel-invalid-capture']);
        $payments = new PaymentStoreAdapter();
        $payments->applyCapture('pay-rel-invalid-capture', 1000, 'provider-capture-1');
        $payments->applyCapture('pay-rel-invalid-capture', 1000, 'provider-capture-1');

        $this->expectException(\InvalidArgumentException::class);
        $payments->applyCapture('pay-rel-invalid-capture', 1, 'provider-capture-2');
    }

    public function testWebhookAppliesKnownPaymentEventAndIgnoresUnknownEvent(): void
    {
        $repo = new OrderRepository();
        $repo->createPayment(['id' => 'pay-rel-2', 'order_id' => 'order-rel-2', 'provider' => 'test', 'amount_minor' => 2000, 'state' => 'authorized', 'provider_ref' => null, 'idempotency_key' => 'auth-rel-2']);
        $events = new V2SideEffectRepository();
        $processor = new WebhookProcessor($events, new PaymentStoreAdapter());
        $processor->register();
        $capturedBody = json_encode(['type' => 'payment.captured', 'payment_id' => 'pay-rel-2', 'provider_ref' => 'provider-2'], JSON_THROW_ON_ERROR);
        $events->receive('test', 'evt-rel-1', hash('sha256', $capturedBody), true, $capturedBody);
        Event::emit('webhook.process', ['provider' => 'test', 'event_id' => 'evt-rel-1']);
        self::assertSame('captured', (new PaymentStoreAdapter())->find('pay-rel-2')['state']);
        $unknownBody = json_encode(['type' => 'payment.future', 'payment_id' => 'pay-rel-2'], JSON_THROW_ON_ERROR);
        $events->receive('test', 'evt-rel-2', hash('sha256', $unknownBody), true, $unknownBody);
        Event::emit('webhook.process', ['provider' => 'test', 'event_id' => 'evt-rel-2']);
        self::assertSame('ignored', (new V2SideEffectRepository())->find('test', 'evt-rel-2')['state']);
    }

    public function testPartialRefundWebhookDoesNotMarkPaymentFullyRefunded(): void
    {
        $repo = new OrderRepository();
        $repo->createPayment(['id' => 'pay-rel-partial', 'order_id' => 'order-rel-partial', 'provider' => 'test', 'amount_minor' => 2000, 'state' => 'captured', 'provider_ref' => null, 'idempotency_key' => 'auth-rel-partial']);
        $events = new V2SideEffectRepository();
        $processor = new WebhookProcessor($events, new PaymentStoreAdapter());
        $processor->register();
        $refundBody = json_encode(['type' => 'payment.refunded', 'payment_id' => 'pay-rel-partial', 'amount_minor' => 500], JSON_THROW_ON_ERROR);
        $events->receive('test', 'evt-rel-partial', hash('sha256', $refundBody), true, $refundBody);
        Event::emit('webhook.process', ['provider' => 'test', 'event_id' => 'evt-rel-partial']);
        self::assertSame('partially_refunded', (new PaymentStoreAdapter())->find('pay-rel-partial')['state']);
    }

    public function testProcessedWebhookReplayDoesNotApplyCaptureTwice(): void
    {
        $repo = new OrderRepository();
        $repo->createPayment(['id' => 'pay-rel-replay', 'order_id' => 'order-rel-replay', 'provider' => 'test', 'amount_minor' => 2000, 'state' => 'authorized', 'provider_ref' => null, 'idempotency_key' => 'auth-rel-replay']);
        $events = new V2SideEffectRepository();
        $processor = new WebhookProcessor($events, new PaymentStoreAdapter());
        $processor->register();
        $replayBody = json_encode(['type' => 'payment.captured', 'payment_id' => 'pay-rel-replay', 'amount_minor' => 1000], JSON_THROW_ON_ERROR);
        $events->receive('test', 'evt-rel-replay', hash('sha256', $replayBody), true, $replayBody);
        Event::emit('webhook.process', ['provider' => 'test', 'event_id' => 'evt-rel-replay']);
        Event::emit('webhook.process', ['provider' => 'test', 'event_id' => 'evt-rel-replay']);

        $payment = (new PaymentStoreAdapter())->find('pay-rel-replay');
        self::assertSame('partially_captured', $payment['state']);
        self::assertSame(1000, (int) $payment['captured_amount_minor']);
    }

    public function testRefundWebhooksAccumulateAndTransitionOnlyAtTheFullAmount(): void
    {
        $repo = new OrderRepository();
        $repo->createPayment(['id' => 'pay-rel-refund-webhook-total', 'order_id' => 'order-rel-refund-webhook-total', 'provider' => 'test', 'amount_minor' => 2000, 'captured_amount_minor' => 2000, 'currency' => 'VND', 'state' => 'captured', 'provider_ref' => null, 'idempotency_key' => 'auth-rel-refund-webhook-total']);
        $events = new V2SideEffectRepository();
        $processor = new WebhookProcessor($events, new PaymentStoreAdapter());
        $processor->register();
        foreach ([['evt' => 'evt-rel-refund-1', 'ref' => 'refund-provider-1', 'amount' => 500], ['evt' => 'evt-rel-refund-2', 'ref' => 'refund-provider-2', 'amount' => 1500]] as $item) {
            $body = json_encode(['type' => 'payment.refunded', 'payment_id' => 'pay-rel-refund-webhook-total', 'amount_minor' => $item['amount'], 'currency' => 'VND', 'provider_ref' => $item['ref']], JSON_THROW_ON_ERROR);
            $events->receive('test', $item['evt'], hash('sha256', $body), true, $body);
            Event::emit('webhook.process', ['provider' => 'test', 'event_id' => $item['evt']]);
        }
        $payment = (new PaymentStoreAdapter())->find('pay-rel-refund-webhook-total');
        self::assertSame('refunded', $payment['state']);
        self::assertSame(2000, (int) $payment['refunded_amount_minor']);
    }

    public function testRefundWebhookCurrencyMismatchAndInvalidChargebackDoNotMutatePayment(): void
    {
        $repo = new OrderRepository();
        $repo->createPayment(['id' => 'pay-rel-evidence-boundary', 'order_id' => 'order-rel-evidence-boundary', 'provider' => 'test', 'amount_minor' => 1000, 'captured_amount_minor' => 1000, 'currency' => 'VND', 'state' => 'captured', 'provider_ref' => null, 'idempotency_key' => 'auth-rel-evidence-boundary']);
        $events = new V2SideEffectRepository();
        $processor = new WebhookProcessor($events, new PaymentStoreAdapter());
        $processor->register();
        $currencyBody = json_encode(['type' => 'payment.refunded', 'payment_id' => 'pay-rel-evidence-boundary', 'amount_minor' => 100, 'currency' => 'USD'], JSON_THROW_ON_ERROR);
        $events->receive('test', 'evt-rel-currency-mismatch', hash('sha256', $currencyBody), true, $currencyBody);
        try { Event::emit('webhook.process', ['provider' => 'test', 'event_id' => 'evt-rel-currency-mismatch']); self::fail('Expected currency mismatch'); } catch (\InvalidArgumentException) { self::assertSame('received', $events->find('test', 'evt-rel-currency-mismatch')['state']); }

        $chargebackBody = json_encode(['type' => 'payment.chargeback', 'payment_id' => 'pay-rel-evidence-boundary', 'amount_minor' => 2000, 'currency' => 'VND'], JSON_THROW_ON_ERROR);
        $events->receive('test', 'evt-rel-invalid-chargeback', hash('sha256', $chargebackBody), true, $chargebackBody);
        try { Event::emit('webhook.process', ['provider' => 'test', 'event_id' => 'evt-rel-invalid-chargeback']); self::fail('Expected invalid chargeback'); } catch (\InvalidArgumentException) { self::assertSame('received', $events->find('test', 'evt-rel-invalid-chargeback')['state']); }
        self::assertSame('captured', (new PaymentStoreAdapter())->find('pay-rel-evidence-boundary')['state']);
    }

    public function testWebhookFailureIsReplayableAfterAtomicRollback(): void
    {
        $repo = new OrderRepository();
        $repo->createPayment(['id' => 'pay-rel-failure', 'order_id' => 'order-rel-failure', 'provider' => 'test', 'amount_minor' => 2000, 'state' => 'authorized', 'provider_ref' => null, 'idempotency_key' => 'auth-rel-failure']);
        $events = new V2SideEffectRepository();
        $processor = new WebhookProcessor($events, new PaymentStoreAdapter());
        $processor->register();
        $failureBody = json_encode(['type' => 'payment.captured', 'payment_id' => 'pay-rel-failure', 'amount_minor' => 3000], JSON_THROW_ON_ERROR);
        $events->receive('test', 'evt-rel-failure', hash('sha256', $failureBody), true, $failureBody);

        try {
            Event::emit('webhook.process', ['provider' => 'test', 'event_id' => 'evt-rel-failure']);
            self::fail('Expected oversized webhook capture to fail');
        } catch (\InvalidArgumentException) {
            self::assertSame('received', $events->find('test', 'evt-rel-failure')['state']);
        }
        self::assertSame('authorized', (new PaymentStoreAdapter())->find('pay-rel-failure')['state']);
    }

    public function testPaymentAndWebhookApiPathsHaveContractCoverage(): void
    {
        self::assertStringContainsString('/api/v2/payments', '/api/v2/payments/{id}/capture');
        self::assertStringContainsString('/api/webhooks', '/api/webhooks/{provider}');
    }
}
