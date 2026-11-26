<?php
declare(strict_types=1);
namespace App\Modules\Payment\Application;

use App\Modules\Payment\Contracts\PaymentReconciliationProvider;
use App\Modules\Payment\Contracts\PaymentReconciliationStore;
use App\Modules\Payment\Contracts\PaymentEvidenceStore;
use App\Modules\Payment\Contracts\PaymentCaptureStore;
use App\Modules\Payment\Contracts\PaymentStore;
use App\Support\Arr;
use Siro\Core\Metrics;

final class PaymentReconciliationService
{
    public function __construct(private readonly PaymentReconciliationStore $operations, private readonly ?PaymentStore $payments = null) {}
    /** @return array{inspected: int, resolved: int, unknown: int, operations: array<int, array<string, mixed>>} */
    public function reconcile(PaymentReconciliationProvider $provider, bool $apply = false): array
    {
        $rows = $this->operations->unresolved(); $resolved = 0; $unknown = 0;
        foreach ($rows as $row) {
            /** @var array<string, mixed> $row */
            $hasProviderOperation = is_scalar($row['payment_id'] ?? null) && is_scalar($row['type'] ?? null);
            $result = $apply && $hasProviderOperation
                ? ['source' => 'provider', 'checked_at' => date(DATE_ATOM), 'operation_id' => is_scalar($row['id'] ?? null) ? (string) $row['id'] : ''] + $provider->reconcile($row)
                : ['state' => 'unknown', 'source' => 'local', 'provider_operation_skipped' => true];
            $state = is_string($result['state'] ?? null) ? $result['state'] : 'unknown';
            $canResolve = $this->canResolve($row, $state);
            if ($apply && $canResolve) $canResolve = $this->applyPayment($row, $result, $state);
            if ($apply && $canResolve && is_scalar($row['id'] ?? null)) $this->operations->reconcile((string) $row['id'], $result);
            if ($apply && is_scalar($row['id'] ?? null) && $this->operations instanceof PaymentEvidenceStore) $this->operations->recordEvidence((string) $row['id'], $result);
            if ($canResolve) $resolved++; else $unknown++;
        }
        Metrics::counter('commerce_payment_reconciliation_total', count($rows));
        Metrics::counter('commerce_payment_reconciliation_resolved_total', $apply ? $resolved : 0);
        Metrics::counter('commerce_payment_reconciliation_unknown_total', $unknown);
        return ['inspected' => count($rows), 'resolved' => $apply ? $resolved : 0, 'unknown' => $unknown, 'operations' => $rows];
    }

    /** @param array<string, mixed> $operation */
    private function canResolve(array $operation, string $state): bool
    {
        return match (Arr::str($operation, 'type')) {
            'authorize' => $state === 'authorized',
            'capture' => in_array($state, ['captured', 'partially_captured'], true),
            'refund' => in_array($state, ['refunded', 'partially_refunded'], true),
            'void' => $state === 'voided',
            default => $state === 'failed',
        } || $state === 'failed';
    }

    /**
     * @param array<string, mixed> $operation
     * @param array<string, mixed> $result
     */
    private function applyPayment(array $operation, array $result, string $state): bool
    {
        if ($this->payments === null) return true;
        $paymentId = Arr::nullableStr($operation, 'payment_id');
        if ($paymentId === null) return false;
        $payment = $this->payments->find($paymentId);
        if ($payment === null) return false;
        $type = Arr::str($operation, 'type');
        $providerRef = Arr::nullableStr($result, 'provider_ref');
        if ($type === 'capture' && in_array($state, ['captured', 'partially_captured'], true)) {
            $amount = Arr::int($operation, 'amount_minor');
            if ($amount <= 0) return false;
            if ($this->payments instanceof PaymentCaptureStore) $this->payments->applyCapture($paymentId, $amount, $providerRef);
            else {
                $this->payments->setCapturedAmount($paymentId, Arr::int($payment, 'captured_amount_minor') + $amount);
                $this->payments->setState($paymentId, $state, $providerRef);
            }
            return true;
        }
        if ($state !== 'failed') $this->payments->setState($paymentId, $state, $providerRef);
        return true;
    }
}
