<?php

declare(strict_types=1);

namespace App\Modules\Payment\Application;

use App\Modules\Payment\Contracts\PaymentOperationStore;
use App\Modules\Payment\Contracts\PaymentRefundStore;
use App\Modules\Payment\Contracts\PaymentProvider;
use App\Modules\Payment\Domain\PaymentOperation;
use App\Modules\Shared\Application\Result;
use App\Support\Arr;

/** Provider orchestration without owning persistence or HTTP concerns. */
final class PaymentOperationService
{
    public function __construct(private readonly PaymentOperationStore $operations)
    {
    }

    /** @return array<string, mixed>|null */
    public function existing(string $idempotencyKey): ?array
    {
        return $this->operations->findByIdempotency($idempotencyKey);
    }

    public function refundedAmount(string $paymentId): int
    {
        return $this->operations instanceof PaymentRefundStore ? $this->operations->refundedAmount($paymentId) : 0;
    }

    /** @param array<string, mixed> $context @return Result<array<string, mixed>> */
    public function execute(PaymentOperation $operation, PaymentProvider $provider, array $context = []): Result
    {
        $existing = $this->existing($operation->idempotencyKey);
        if ($existing !== null) {
            return $this->replay($existing);
        }
        try {
            $this->operations->create([
                'id' => $operation->id,
                'payment_id' => $operation->paymentId,
                'type' => $operation->type,
                'amount_minor' => $operation->amount->amountMinor,
                'currency' => $operation->amount->currency,
                'idempotency_key' => $operation->idempotencyKey,
                'state' => 'processing',
                'request_hash' => hash('sha256', json_encode([
                    'payment_id' => $operation->paymentId,
                    'type' => $operation->type,
                    'amount_minor' => $operation->amount->amountMinor,
                    'currency' => $operation->amount->currency,
                    'idempotency_key' => $operation->idempotencyKey,
                ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)),
            ]);
        } catch (\Throwable $e) {
            // The unique idempotency index is the concurrency authority. Never
            // call the provider twice when another request won the insert race.
            $existing = $this->existing($operation->idempotencyKey);
            if ($existing !== null) return $this->replay($existing);
            throw $e;
        }
        try {
            $result = $provider->execute($operation, $context);
            $this->operations->complete($operation->id, $result);
            if (in_array($result['state'] ?? null, ['failed', 'unknown'], true)) {
                return Result::failure('payment.operation_failed', Arr::str($result, 'error', 'Payment operation did not succeed'));
            }
            return Result::success($result);
        } catch (\Throwable $e) {
            // A provider timeout is unknown, never an automatic retryable charge failure.
            $this->operations->complete($operation->id, [
                'state' => 'unknown',
                'error_code' => 'provider.unknown',
                'error' => 'Payment provider operation failed before a definitive outcome was available',
            ]);
            return Result::failure('payment.operation_failed', 'Payment operation could not be confirmed; reconciliation required');
        }
    }

    /** @param array<string, mixed> $existing @return Result<array<string, mixed>> */
    public function replay(array $existing): Result
    {
        $state = Arr::str($existing, 'state');
        if (in_array($state, ['authorized', 'succeeded', 'captured', 'partially_captured', 'refunded', 'cancelled', 'voided'], true)) {
            return Result::success($existing);
        }
        if ($state === 'unknown') {
            return Result::failure('payment.operation_unknown', 'Payment operation requires reconciliation before retry');
        }
        return Result::failure('payment.operation_in_progress', 'Payment operation is already in progress');
    }
}
