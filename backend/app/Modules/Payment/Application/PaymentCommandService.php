<?php

declare(strict_types=1);

namespace App\Modules\Payment\Application;

use App\Modules\Payment\Contracts\PaymentProvider;
use App\Modules\Payment\Contracts\PaymentStore;
use App\Modules\Payment\Contracts\PaymentCaptureStore;
use App\Modules\Payment\Contracts\PaymentRefundStateStore;
use App\Modules\Payment\Domain\PaymentOperation;
use App\Modules\Shared\Domain\Money;
use App\Modules\Shared\Domain\Currency;
use App\Modules\Shared\Application\CommandContext;
use App\Modules\Shared\Contracts\AuditWriter;
use App\Support\Arr;
use App\Support\Ids;
use App\Modules\Shared\Hooks\HookName;
use App\Modules\Shared\Hooks\Hooks;
use Siro\Core\Event;

final class PaymentCommandService
{
    public function __construct(private readonly PaymentStore $payments, private readonly PaymentOperationService $operations, private readonly PaymentProvider $provider, private readonly ?AuditWriter $audit = null) {}

    public function refundedAmount(string $paymentId): int
    {
        return $this->operations->refundedAmount($paymentId);
    }

    /** @return array<string, mixed>|null */
    public function find(string $paymentId): ?array
    {
        return $this->payments->find($paymentId);
    }

    /** @return array<string, mixed> */
    /** @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public function execute(string $paymentId, string $type, int $amountMinor, string $currency, string $idempotencyKey, array $context = []): array
    {
        if (!in_array($type, ['authorize', 'capture', 'refund', 'void'], true)) throw new \InvalidArgumentException('Unsupported payment operation');
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$/', $idempotencyKey) !== 1) throw new \InvalidArgumentException('A valid Idempotency-Key is required');
        $payment = $this->payments->find($paymentId);
        if ($payment === null) throw new \RuntimeException('Payment not found');
         if ($amountMinor <= 0 || $amountMinor > Money::MAX_MINOR) throw new \InvalidArgumentException('Payment amount must be positive and fit a signed INT');
        $currency = Currency::normalize($currency);
        $storedCurrency = Currency::normalize(Arr::str($payment, 'currency', 'VND'));
        if ($currency !== $storedCurrency) throw new \InvalidArgumentException('Payment currency mismatch');
        $existing = $this->operations->existing($idempotencyKey);
        if ($existing !== null) {
            if (Arr::str($existing, 'payment_id') !== $paymentId || Arr::str($existing, 'type') !== $type || Arr::int($existing, 'amount_minor') !== $amountMinor || Currency::normalize(Arr::str($existing, 'currency', $currency)) !== $currency) {
                throw new \InvalidArgumentException('Idempotency key was already used for a different payment operation');
            }
            $replayed = $this->operations->replay($existing);
            if (!$replayed->ok) {
                $alreadyApplied = ($type === 'capture' && in_array(Arr::str($payment, 'state'), ['partially_captured', 'captured'], true)) || ($type === 'void' && Arr::str($payment, 'state') === 'voided') || ($type === 'refund' && in_array(Arr::str($payment, 'state'), ['partially_refunded', 'refunded'], true));
                if ($alreadyApplied) return $payment;
                throw new \RuntimeException($replayed->message ?? 'Payment operation is not replayable');
            }
            // The operation record is the idempotency fence. A completed replay
            // must not apply the payment mutation a second time.
            return $payment;
        }
        $state = Arr::str($payment, 'state');
        if ($type === 'authorize' && $state !== 'pending') throw new \InvalidArgumentException('Only pending payments can be authorized');
        if ($type === 'capture' && !in_array($state, ['authorized', 'partially_captured'], true)) throw new \InvalidArgumentException('Only authorized payments can be captured');
        if ($type === 'void' && $state !== 'authorized') throw new \InvalidArgumentException('Only authorized payments can be voided');
        if ($type === 'refund' && !in_array($state, ['captured', 'partially_captured', 'partially_refunded'], true)) throw new \InvalidArgumentException('Only captured payments can be refunded');
        if ($type === 'refund') {
            $capturedAmount = $this->capturedAmount($payment);
            // The operation ledger covers command refunds; the payment counter
            // also includes provider refunds received through webhooks.
            $refundedAmount = max($this->operations->refundedAmount($paymentId), Arr::int($payment, 'refunded_amount_minor'));
             if ($refundedAmount < 0 || $refundedAmount > $capturedAmount || $amountMinor > $capturedAmount - $refundedAmount) {
                throw new \InvalidArgumentException('Refund amount exceeds the remaining captured amount');
            }
        }
         $availableCapture = $type === 'capture' ? Arr::int($payment, 'amount_minor') - Arr::int($payment, 'captured_amount_minor') : $this->capturedAmount($payment);
         if ($availableCapture < 0) throw new \InvalidArgumentException('Payment has an invalid captured amount');
        // Fraud/risk modules may veto the capture (reason surfaces as 422);
        // the veto runs before any provider call, so nothing moves on reject.
        if ($type === 'capture') Hooks::fire(HookName::PaymentBeforeCapture, ['payment_id' => $paymentId, 'amount_minor' => $amountMinor, 'currency' => $currency, 'order_id' => Arr::nullableStr($context, 'order_id')]);
        if (in_array($type, ['capture', 'refund'], true) && $amountMinor > $availableCapture) {
            throw new \InvalidArgumentException('Payment amount exceeds the authorized amount');
        }
        $operation = new PaymentOperation(Ids::new(), $paymentId, $type, new Money($amountMinor, $currency), $idempotencyKey);
        // The registry must receive the persisted provider; defaulting here would
        // silently execute a configured payment through COD.
        $result = $this->operations->execute($operation, $this->provider, $context + [
            'payment_id' => $paymentId,
            'provider' => Arr::str($payment, 'provider', 'cod'),
        ]);
        if (!$result->ok) {
            if ($type === 'capture') {
                $orderId = Arr::nullableStr($context, 'order_id');
                if ($orderId !== null) Event::emit('payment.failed', ['order_id' => $orderId, 'payment_id' => $paymentId]);
            }
            throw new \RuntimeException($result->message ?? 'Payment operation failed');
        }
        $value = $this->map($result->value);
        if ($type === 'refund' && $this->payments instanceof PaymentRefundStateStore) {
            $this->payments->applyRefund($paymentId, $amountMinor, Arr::nullableStr($value, 'provider_ref'));
        }
        $nextState = match ($type) {
            'authorize' => 'authorized',
            'capture' => $this->captureState($payment, $amountMinor),
            'void' => 'voided',
            // The operation is already persisted as succeeded at this point,
            // so adding the command amount here would count the current refund twice.
            'refund' => max($this->operations->refundedAmount($paymentId), Arr::int($payment, 'refunded_amount_minor')) >= $this->capturedAmount($payment) ? 'refunded' : 'partially_refunded',
        };
        if ($type === 'capture' && $this->payments instanceof PaymentCaptureStore) {
            $this->payments->applyCapture($paymentId, $amountMinor, Arr::nullableStr($value, 'provider_ref'));
        } else {
            $this->payments->setState($paymentId, $nextState, Arr::nullableStr($value, 'provider_ref'));
             if ($type === 'capture') $this->payments->setCapturedAmount($paymentId, (new Money(Arr::int($payment, 'captured_amount_minor'), $currency))->add(new Money($amountMinor, $currency))->amountMinor);
        }
        $updated = $this->payments->find($paymentId);
        if ($updated === null) throw new \RuntimeException('Payment lost after operation');
        if ($type === 'capture') {
            $orderId = Arr::nullableStr($context, 'order_id');
            if ($orderId !== null) Event::emit('payment.captured', ['order_id' => $orderId, 'payment_id' => $paymentId]);
        }
        $this->audit($type, $payment, $updated, $context, $idempotencyKey);
        return $updated;
    }

    /** @return array<string, mixed> */
    private function map(mixed $value): array
    {
        $result = [];
        if (is_array($value)) foreach ($value as $key => $item) if (is_string($key)) $result[$key] = $item;
        return $result;
    }

    /** @param array<string, mixed> $payment */
    private function captureState(array $payment, int $amountMinor): string
    {
         return (new Money(Arr::int($payment, 'captured_amount_minor'), Arr::str($payment, 'currency', 'VND')))->add(new Money($amountMinor, Arr::str($payment, 'currency', 'VND')))->amountMinor >= Arr::int($payment, 'amount_minor') ? 'captured' : 'partially_captured';
    }

    /** @param array<string, mixed> $payment */
    private function capturedAmount(array $payment): int
    {
        $captured = Arr::int($payment, 'captured_amount_minor');
        if ($captured === 0 && in_array(Arr::str($payment, 'state'), ['captured', 'partially_refunded', 'refunded'], true)) return Arr::int($payment, 'amount_minor');
        return $captured;
    }

    /**
     * @param array<string, mixed> $before
     * @param array<string, mixed> $after
     * @param array<string, mixed> $context
     */
    private function audit(string $type, array $before, array $after, array $context, string $idempotencyKey): void
    {
        if ($this->audit === null) return;
        $actor = Arr::str($context, 'actor', 'system');
        $correlation = Arr::str($context, 'correlation_id', $idempotencyKey);
        $causation = Arr::str($context, 'causation_id', $idempotencyKey);
        $command = new CommandContext($actor, $correlation, $causation, $idempotencyKey, Arr::str($context, 'source', 'api'));
        $this->audit->record($command, 'payment.' . $type, 'payment', Arr::str($after, 'id'), $this->auditSnapshot($before), $this->auditSnapshot($after), 'payment mutation completed');
    }

    /**
     * @param array<string, mixed> $snapshot
     * @return array<string, mixed>
     */
    private function auditSnapshot(array $snapshot): array
    {
        /** @var array<string, mixed> $safe */
        $safe = [];
        foreach (['id', 'state', 'amount_minor', 'captured_amount_minor', 'currency', 'provider', 'provider_ref'] as $key) {
            if (array_key_exists($key, $snapshot) && (is_string($snapshot[$key]) || is_int($snapshot[$key]) || $snapshot[$key] === null)) $safe[$key] = $snapshot[$key];
        }
        return $safe;
    }
}
