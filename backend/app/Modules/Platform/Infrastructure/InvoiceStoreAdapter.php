<?php

declare(strict_types=1);

namespace App\Modules\Platform\Infrastructure;

use App\Models\CreditMemo;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Payment;
use App\Models\OrderLine;
use App\Modules\Finance\Contracts\InvoiceStore;
use App\Repositories\Concerns\CreatesModels;
use App\Support\Arr;
use Siro\Core\Database;
use App\Support\Ids;

final class InvoiceStoreAdapter implements InvoiceStore
{
    use CreatesModels;
    public function transaction(callable $callback): mixed { return Database::transaction($callback); }

    public function byIdempotency(string $key): ?array { return $this->row(Invoice::query()->where('idempotency_key', $key)->first()); }
    public function find(string $id): ?array { return $this->row(Invoice::find($id)); }
    public function create(array $data): array
    {
        $lines = is_array($data['line_snapshots'] ?? null) ? $data['line_snapshots'] : [];
        $taxes = is_array($data['tax_snapshots'] ?? null) ? $data['tax_snapshots'] : [];
        unset($data['line_snapshots'], $data['tax_snapshots']);
        try {
            return Arr::row(Database::transaction(function () use ($data, $lines, $taxes): array {
                $result = self::createRow(Invoice::class, $data);
                foreach ($lines as $line) if (is_array($line)) self::createRow(\App\Models\InvoiceLine::class, array_merge(Arr::row($line), ['id' => Ids::new(), 'invoice_id' => Arr::requiredStr($result, 'id')]));
                foreach ($taxes as $tax) if (is_array($tax)) self::createRow(\App\Models\InvoiceTax::class, array_merge($this->taxRow($tax), ['id' => Ids::new(), 'invoice_id' => Arr::requiredStr($result, 'id')]));
                return $result;
            }));
        } catch (\Throwable $e) {
            // Core's identity map is not transaction-aware: the header
            // instance was registered on save() even though the DB rolled
            // back. Evict the ghost so find() never returns an uncommitted row.
            Invoice::clearIdentityMap();
            throw $e;
        }
    }

    public function setState(string $id, string $state, ?string $issuedAt = null): void
    {
        Database::transaction(function () use ($id, $state, $issuedAt): void {
            /** @var \Siro\Core\Model|null $invoice */
            $invoice = Invoice::query()->where('id', $id)->lockForUpdate()->first();
            if ($invoice === null) return;
            $current = Arr::str($invoice->toArray(), 'state');
            if ($current === 'paid' && $state !== 'paid') return;
            if ($current === 'issued' && $state === 'draft') return;
            $invoice->setAttribute('state', $state);
            if ($issuedAt !== null) $invoice->setAttribute('issued_at', $issuedAt);
            $invoice->save();
        });
    }

    public function creditedAmount(string $id): int
    {
        $total = 0;
        foreach (CreditMemo::query()->where('invoice_id', $id)->whereIn('state', ['issued', 'pending'])->get() as $memo) $total += Arr::int(Arr::row($memo), 'amount_minor');
        return $total;
    }

    public function evidence(string $orderId, string $paymentId): array
    {
        $order = $this->row(Order::find($orderId));
        $payment = $this->row(Payment::find($paymentId));
        if ($order === null || $payment === null || Arr::str($payment, 'order_id') !== $orderId) throw new \InvalidArgumentException('Order and payment evidence do not match');
        $lines = [];
        foreach (OrderLine::query()->where('order_id', $orderId)->get() as $line) {
            $snapshot = Arr::row($line);
            $snapshot['line_id'] = Arr::str($snapshot, 'id');
            $snapshot['line_total_minor'] = Arr::int($snapshot, 'qty') * Arr::int($snapshot, 'unit_price_minor');
            $lines[] = $snapshot;
        }
        $tax = json_decode(Arr::str($order, 'tax_evidence_json', '{}'), true);
        return ['order' => $order, 'payment' => $payment, 'lines' => $lines, 'tax' => is_array($tax) ? $tax : []];
    }

    /** @param array<string, mixed> $evidence */
    public function reconcilePayment(string $paymentId, string $paymentState, ?string $providerRef, array $evidence = []): void
    {
        Database::transaction(function () use ($paymentId, $paymentState, $providerRef, $evidence): void {
            /** @var \Siro\Core\Model|null $invoice */
            $invoice = Invoice::query()->where('payment_id', $paymentId)->lockForUpdate()->first();
            if ($invoice === null || !in_array($paymentState, ['captured', 'refunded', 'partially_refunded'], true)) return;
            $data = $invoice->toArray();
            $state = Arr::str($data, 'state');
            if ($paymentState === 'captured' && $state === 'issued') $invoice->setAttribute('state', 'paid');
            if (in_array($paymentState, ['refunded', 'partially_refunded'], true) && in_array($state, ['issued', 'paid'], true)) $invoice->setAttribute('state', $paymentState === 'refunded' ? 'refunded' : 'partially_refunded');
            // The issued document is immutable. Payment webhook evidence is retained in audit storage.
            \Siro\Core\Audit::log('commerce.invoice.payment_reconciled', ['invoice_id' => $invoice->getAttribute('id'), 'payment_state' => $paymentState, 'provider_ref' => $providerRef, 'evidence' => \App\Support\ProviderEvidence::redact($evidence)]);
            $invoice->save();
        });
    }

    /** @return array<string, mixed>|null */
    private function row(mixed $model): ?array { return $model instanceof \Siro\Core\Model ? Arr::row($model) : null; }

    /** @return array<string, mixed> */
    private function taxRow(array $tax): array
    {
        $row = Arr::row($tax);
        if (!isset($row['code'])) $row['code'] = Arr::str($row, 'tax_class');
        if (!isset($row['rate']) && isset($row['rate_basis_points'])) $row['rate'] = (string) $row['rate_basis_points'];
        if (is_array($row['evidence'] ?? null)) $row['evidence_json'] = json_encode(\App\Support\ProviderEvidence::redact($row['evidence']), JSON_THROW_ON_ERROR);
        unset($row['evidence']);
        return $row;
    }
}
