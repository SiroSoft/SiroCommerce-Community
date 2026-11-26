<?php
declare(strict_types=1);
namespace App\Modules\Webhook\Application;

use App\Modules\Shared\Domain\Money;

use App\Modules\Webhook\Contracts\WebhookEventStore;
use App\Modules\Payment\Contracts\PaymentStore;
use App\Modules\Finance\Contracts\InvoiceStore;
use App\Support\Arr;
use App\Modules\Shared\Contracts\TransactionRunner;
use App\Modules\Payment\Contracts\PaymentCaptureStore;
use App\Modules\Payment\Contracts\PaymentRefundStateStore;
use Siro\Core\Audit;
use Siro\Core\Event;
use Siro\Core\Metrics;
use App\Modules\Subscriptions\Application\SubscriptionService;

/** Processes the durable webhook job after signature verification and dedupe. */
final class WebhookProcessor
{
    public function __construct(private readonly WebhookEventStore $events, private readonly PaymentStore $payments, private readonly ?InvoiceStore $invoices = null, private readonly ?SubscriptionService $subscriptions = null) {}

    public function register(): void
    {
        Event::off('webhook.process');
        Event::on('webhook.process', function (mixed $payload): void {
            if (!is_array($payload) || !is_string($payload['provider'] ?? null) || !is_string($payload['event_id'] ?? null)) {
                throw new \RuntimeException('Webhook job payload is invalid');
            }
            $provider = $payload['provider']; $eventId = $payload['event_id'];
            $process = function () use ($provider, $eventId): void {
            $event = $this->events->find($provider, $eventId);
            if (!$this->events->claimWebhook($provider, $eventId)) return;
            try {
            $eventMap = $this->map($event);
            if (in_array(Arr::str($eventMap, 'state'), ['processed', 'ignored'], true)) return;
            $body = json_decode(Arr::str($eventMap, 'payload_json'), true);
            $bodyMap = $this->map($body);
            $type = Arr::str($bodyMap, 'type', Arr::str($bodyMap, 'event_type'));
            $subscriptionId = Arr::str($bodyMap, 'subscription_id');
            if ($subscriptionId !== '' && $this->subscriptions !== null) {
                $subscriptionState = Arr::str($bodyMap, 'state');
                if ($subscriptionState === '') $subscriptionState = ['payment.authorized' => 'authorized', 'payment.captured' => 'captured', 'payment.failed' => 'failed', 'payment.declined' => 'declined'][$type] ?? $type;
                $this->subscriptions->reconcileWebhook($subscriptionId, $subscriptionState, $eventId);
            }
            $paymentId = Arr::str($bodyMap, 'payment_id');
            $isDispute = str_contains(strtolower($type), 'chargeback') || str_contains(strtolower($type), 'dispute');
            if ($isDispute) {
                if ($paymentId === '') throw new \InvalidArgumentException('Chargeback evidence requires a payment reference');
                $payment = $this->payments->find($paymentId);
                if ($payment === null) throw new \RuntimeException('Webhook payment reference is unknown');
                if (array_key_exists('amount_minor', $bodyMap) && (Arr::int($bodyMap, 'amount_minor') <= 0 || Arr::int($bodyMap, 'amount_minor') > Arr::int($payment, 'amount_minor'))) throw new \InvalidArgumentException('Chargeback amount is invalid');
                if (array_key_exists('currency', $bodyMap) && \App\Modules\Shared\Domain\Currency::normalize(Arr::str($bodyMap, 'currency')) !== \App\Modules\Shared\Domain\Currency::normalize(Arr::str($payment, 'currency', 'VND'))) throw new \InvalidArgumentException('Chargeback currency mismatch');
                // Dispute webhooks are evidence only. They never confirm a refund
                // or mutate the payment/order state without a separate decision.
            }
            $state = ['payment.authorized' => 'authorized', 'payment.captured' => 'captured', 'payment.voided' => 'voided', 'payment.refunded' => 'refunded'][$type] ?? null;
            if ($state !== null && $paymentId !== '') {
                $payment = $this->payments->find($paymentId);
                if ($payment === null) throw new \RuntimeException('Webhook payment reference is unknown');
                if ($type === 'payment.refunded') {
                    $currency = Arr::str($bodyMap, 'currency', Arr::str($payment, 'currency', 'VND'));
                    if (\App\Modules\Shared\Domain\Currency::normalize($currency) !== \App\Modules\Shared\Domain\Currency::normalize(Arr::str($payment, 'currency', 'VND'))) throw new \InvalidArgumentException('Webhook refund currency mismatch');
                    if (array_key_exists('amount_minor', $bodyMap)) {
                        $refundAmount = Arr::int($bodyMap, 'amount_minor');
                        if ($refundAmount <= 0 || $refundAmount > Money::MAX_MINOR) throw new \InvalidArgumentException('Webhook refund amount is invalid');
                        if ($this->payments instanceof PaymentRefundStateStore) {
                            $state = $this->payments->applyRefund($paymentId, $refundAmount, Arr::nullableStr($bodyMap, 'provider_ref'));
                        } else {
                            $state = $refundAmount >= Arr::int($payment, 'amount_minor') ? 'refunded' : 'partially_refunded';
                        }
                    }
                }
                if ($type === 'payment.captured' && array_key_exists('amount_minor', $bodyMap)) {
                     $captureAmount = Arr::int($bodyMap, 'amount_minor');
                     if ($captureAmount <= 0 || $captureAmount > Money::MAX_MINOR) throw new \InvalidArgumentException('Webhook capture amount must be positive and fit a signed INT');
                    if ($this->payments instanceof PaymentCaptureStore) {
                        $this->payments->applyCapture($paymentId, $captureAmount, Arr::nullableStr($bodyMap, 'provider_ref'));
                        $payment = $this->payments->find($paymentId) ?? $payment;
                        $state = Arr::str($payment, 'state');
                    } else {
                         $captured = (new Money(Arr::int($payment, 'captured_amount_minor'), Arr::str($payment, 'currency', 'VND')))->add(new Money($captureAmount, Arr::str($payment, 'currency', 'VND')))->amountMinor;
                        if ($captured > Arr::int($payment, 'amount_minor')) throw new \InvalidArgumentException('Webhook capture exceeds the payment amount');
                        $state = $captured >= Arr::int($payment, 'amount_minor') ? 'captured' : 'partially_captured';
                        $this->payments->setCapturedAmount($paymentId, $captured);
                    }
                }
                if (!($type === 'payment.captured' && array_key_exists('amount_minor', $bodyMap) && $this->payments instanceof PaymentCaptureStore)) {
                    $this->payments->setState($paymentId, $state, Arr::nullableStr($bodyMap, 'provider_ref'));
                }
                $this->invoices?->reconcilePayment($paymentId, $state, Arr::nullableStr($bodyMap, 'provider_ref'), $bodyMap);
            }
            $result = $state === null ? 'ignored' : 'processed';
            $this->events->markProcessed($provider, $eventId, $result);
            Audit::log('commerce.webhook.' . $result, ['provider' => $provider, 'event_id' => $eventId, 'type' => $type]);
            Metrics::counter('commerce_webhook_processed_total', 1, ['provider' => $provider]);
            Metrics::counter('commerce_webhook_' . $result . '_total', 1, ['provider' => $provider]);
            Event::emit('webhook.processed', ['provider' => $provider, 'event_id' => $eventId]);
            } catch (\Throwable $exception) {
                $this->events->markQueued($provider, $eventId);
                throw $exception;
            }
            };
            if ($this->events instanceof TransactionRunner) {
                $this->events->transaction($process);
            } else {
                $process();
            }
        });
    }

    /** @return array<string, mixed> */
    private function map(mixed $value): array
    {
        $result = [];
        if (is_array($value)) foreach ($value as $key => $item) if (is_string($key)) $result[$key] = $item;
        return $result;
    }
}
