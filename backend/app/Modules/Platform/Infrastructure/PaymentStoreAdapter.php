<?php

declare(strict_types=1);

namespace App\Modules\Platform\Infrastructure;

use App\Models\Payment;
use App\Modules\Payment\Contracts\PaymentStore;
use App\Modules\Payment\Contracts\PaymentCaptureStore;
use App\Modules\Payment\Contracts\PaymentRefundStateStore;
use App\Support\Arr;
use Siro\Core\Database;

final class PaymentStoreAdapter implements PaymentStore, PaymentCaptureStore, PaymentRefundStateStore
{
    public function find(string $id): ?array
    {
        $row = Payment::find($id);
        if ($row === null) return null;
        return Arr::row($row);
    }

    public function setState(string $id, string $state, ?string $providerRef = null): void
    {
        Database::transaction(function () use ($id, $state, $providerRef): void {
        /** @var \Siro\Core\Model|null $row */
        $row = Payment::query()->where('id', $id)->lockForUpdate()->first();
        if ($row === null) return;
        $current = Arr::str($row->toArray(), 'state');
        $allowed = [
            'pending' => ['authorized', 'voided'],
            'authorized' => ['authorized', 'partially_captured', 'captured', 'voided'],
            'partially_captured' => ['partially_captured', 'captured', 'partially_refunded', 'refunded'],
            'captured' => ['captured', 'partially_refunded', 'refunded'],
            'partially_refunded' => ['partially_refunded', 'refunded'],
            'refunded' => ['refunded'],
            'voided' => ['voided'],
        ];
        if (!in_array($state, $allowed[$current] ?? [], true)) return;
        $row->setAttribute('state', $state);
        if ($providerRef !== null) $row->setAttribute('provider_ref', $providerRef);
        $row->save();
        });
    }

    public function setCapturedAmount(string $id, int $capturedAmountMinor): void
    {
        Database::transaction(function () use ($id, $capturedAmountMinor): void {
            /** @var \Siro\Core\Model|null $row */
            $row = Payment::query()->where('id', $id)->lockForUpdate()->first();
            if ($row !== null) { $row->setAttribute('captured_amount_minor', $capturedAmountMinor); $row->save(); }
        });
    }

    public function applyCapture(string $id, int $amountMinor, ?string $providerRef = null): void
    {
        Database::transaction(function () use ($id, $amountMinor, $providerRef): void {
            /** @var \Siro\Core\Model|null $row */
            $row = Payment::query()->where('id', $id)->lockForUpdate()->first();
            if ($row === null) throw new \RuntimeException('Payment not found');
            $data = Arr::row($row);
            $state = Arr::str($data, 'state');
            // Reconciliation can crash after this mutation and before its
            // operation row is resolved. A provider reference is the durable
            // identity of that capture, so replaying it must be a no-op.
            if ($providerRef !== null && $providerRef === Arr::nullableStr($data, 'provider_ref')) return;
            if (!in_array($state, ['authorized', 'partially_captured'], true)) throw new \InvalidArgumentException('Payment capture state changed while processing');
            $captured = Arr::int($data, 'captured_amount_minor') + $amountMinor;
            if ($amountMinor <= 0 || $captured > Arr::int($data, 'amount_minor')) throw new \InvalidArgumentException('Payment capture exceeds the authorized amount');
            $row->setAttribute('captured_amount_minor', $captured);
            $row->setAttribute('state', $captured === Arr::int($data, 'amount_minor') ? 'captured' : 'partially_captured');
            if ($providerRef !== null) $row->setAttribute('provider_ref', $providerRef);
            $row->save();
        });
    }

    public function applyRefund(string $id, int $amountMinor, ?string $providerRef = null): string
    {
        return Database::transaction(function () use ($id, $amountMinor, $providerRef): string {
            /** @var \Siro\Core\Model|null $row */
            $row = Payment::query()->where('id', $id)->lockForUpdate()->first();
            if ($row === null) throw new \RuntimeException('Payment not found');
            $data = Arr::row($row);
            if ($providerRef !== null && $providerRef === Arr::nullableStr($data, 'provider_ref')) return Arr::str($data, 'state');
            if ($amountMinor <= 0 || $amountMinor > \App\Modules\Shared\Domain\Money::MAX_MINOR) throw new \InvalidArgumentException('Refund amount must be positive and fit a signed INT');
            $captured = Arr::int($data, 'captured_amount_minor');
            if ($captured === 0 && in_array(Arr::str($data, 'state'), ['captured', 'partially_refunded', 'refunded'], true)) $captured = Arr::int($data, 'amount_minor');
            $refunded = Arr::int($data, 'refunded_amount_minor');
            if ($refunded < 0 || $refunded + $amountMinor > $captured) throw new \InvalidArgumentException('Refund amount exceeds the remaining captured amount');
            $next = $refunded + $amountMinor >= $captured ? 'refunded' : 'partially_refunded';
            $row->setAttribute('refunded_amount_minor', $refunded + $amountMinor);
            $row->setAttribute('state', $next);
            if ($providerRef !== null) $row->setAttribute('provider_ref', $providerRef);
            $row->save();
            return $next;
        });
    }
}
