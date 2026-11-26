<?php

declare(strict_types=1);

namespace App\Modules\Finance\Application;

use App\Modules\Finance\Contracts\CreditMemoService;
use App\Modules\Finance\Contracts\CreditMemoStore;
use App\Modules\Finance\Contracts\InvoiceStore;
use App\Modules\Payment\Application\PaymentCommandService;
use App\Modules\Promotion\Contracts\PromotionUsageReconciler;
use App\Support\Arr;
use App\Support\Ids;
use Siro\Core\Audit;
use Siro\Core\Metrics;
use Siro\Core\Event;
use App\Modules\Shared\Domain\Currency;
use App\Support\ProviderEvidence;

final class CreditMemoManager implements CreditMemoService
{
    public function __construct(
        private readonly CreditMemoStore $memos,
        private readonly InvoiceStore $invoices,
        private readonly PaymentCommandService $payments,
        private readonly ?PromotionUsageReconciler $promotionUsage = null,
    ) {
    }

    public function create(string $invoiceId, int $amountMinor, string $reason, string $idempotencyKey): array
    {
        if ($idempotencyKey === '') throw new \InvalidArgumentException('Idempotency-Key is required');
        $invoice = $this->invoices->find($invoiceId);
        if ($invoice === null) throw new \RuntimeException('Invoice not found');
        $old = $this->memos->byIdempotency($idempotencyKey);
        if ($old !== null) {
            if (Arr::str($old, 'invoice_id') !== $invoiceId || Arr::int($old, 'amount_minor') !== $amountMinor || Currency::normalize(Arr::str($old, 'currency', 'VND')) !== Currency::normalize(Arr::str($invoice, 'currency', 'VND'))) {
                throw new \InvalidArgumentException('Credit memo idempotency key was reused');
            }
            return $old;
        }
        if (!in_array(Arr::str($invoice, 'state'), ['issued', 'paid'], true)) throw new \InvalidArgumentException('Invoice is not refundable');
        $remaining = Arr::int($invoice, 'amount_minor') - $this->invoices->creditedAmount($invoiceId);
        if ($amountMinor <= 0 || $amountMinor > $remaining) throw new \InvalidArgumentException('Credit memo exceeds invoice balance');
        $currency = Currency::normalize(Arr::str($invoice, 'currency', 'VND'));
        Currency::assertAmount($currency, $amountMinor);
        $invoiceEvidence = json_decode(Arr::str($invoice, 'evidence_json', '{}'), true);
        $lineSnapshots = [];
        if (is_array($invoiceEvidence) && is_array($invoiceEvidence['lines'] ?? null)) foreach ($invoiceEvidence['lines'] as $line) if (is_array($line)) $lineSnapshots[] = Arr::row($line);
        $allocatedLines = $this->allocateLines($lineSnapshots, $amountMinor);
        $taxSnapshots = is_array($invoiceEvidence) && is_array($invoiceEvidence['tax'] ?? null) ? $this->allocateTaxes($invoiceEvidence['tax'], $amountMinor, Arr::int($invoice, 'amount_minor')) : [];

        $refundId = Ids::new();
        $this->memos->createRefund([
            'id' => $refundId,
            'order_id' => Arr::requiredStr($invoice, 'order_id'),
            'payment_id' => Arr::requiredStr($invoice, 'payment_id'),
            'invoice_id' => $invoiceId,
            'amount_minor' => $amountMinor,
            'reason' => $reason,
            'state' => 'pending',
            'currency' => $currency,
            'idempotency_key' => $idempotencyKey,
            'minor_unit' => Currency::minorUnit($currency),
            'tax_minor' => $this->allocateTax(Arr::int($invoice, 'tax_minor'), $amountMinor, Arr::int($invoice, 'amount_minor')),
            'evidence_json' => json_encode(ProviderEvidence::redact(['invoice_id' => $invoiceId, 'currency' => $currency]), JSON_THROW_ON_ERROR),
        ]);
        $paymentId = Arr::requiredStr($invoice, 'payment_id');
        try {
            // Return approval may already have issued the money refund. The
            // credit memo is the accounting record for that refund, not a
            // second charge against the payment.
            if ($this->payments->refundedAmount($paymentId) >= $amountMinor) {
                $payment = $this->payments->find($paymentId);
                if ($payment === null) throw new \RuntimeException('Payment not found');
            } else {
                $payment = $this->payments->execute(
                    $paymentId,
                    'refund',
                    $amountMinor,
                    $currency,
                    $idempotencyKey,
                    ['invoice_id' => $invoiceId, 'reason' => $reason],
                );
            }
        } catch (\Throwable $e) {
            $this->memos->setRefundState($refundId, 'failed');
            Metrics::counter('commerce_refund_failed_total', 1);
            throw $e;
        }
        $promotion = $this->promotionUsage?->reconcile(Arr::requiredStr($invoice, 'order_id'), $amountMinor, Arr::int($invoice, 'amount_minor'), $currency, $idempotencyKey . ':promotion', $reason);
        $this->memos->setRefundState($refundId, 'done');
        $result = $this->memos->create([
            'id' => Ids::new(),
            'number' => 'CM-' . strtoupper(substr(Ids::new(), -10)),
            'invoice_id' => $invoiceId,
            'order_id' => Arr::requiredStr($invoice, 'order_id'),
            'payment_id' => Arr::requiredStr($invoice, 'payment_id'),
            'refund_id' => $refundId,
            'state' => 'issued',
            'currency' => $currency,
            'minor_unit' => Currency::minorUnit($currency),
            'amount_minor' => $amountMinor,
            'reason' => $reason,
            'idempotency_key' => $idempotencyKey,
            'evidence_json' => json_encode(ProviderEvidence::redact(['invoice' => $invoice, 'payment' => $payment, 'lines' => $allocatedLines, 'tax' => $taxSnapshots, 'promotion_reconciliation' => $promotion]), JSON_THROW_ON_ERROR),
            'tax_minor' => $this->allocateTax(Arr::int($invoice, 'tax_minor'), $amountMinor, Arr::int($invoice, 'amount_minor')),
            'line_snapshots' => $allocatedLines,
            'tax_snapshots' => $taxSnapshots,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        Audit::log('commerce.credit_memo.issued', ['credit_memo_id' => Arr::requiredStr($result, 'id'), 'invoice_id' => $invoiceId, 'refund_id' => $refundId]);
        Metrics::counter('commerce_credit_memo_issued_total', 1);
        Event::emit('credit_memo.issued', ['order_id' => Arr::requiredStr($invoice, 'order_id'), 'credit_memo_id' => Arr::requiredStr($result, 'id')]);
        return $result;
    }

    public function find(string $id): ?array { return $this->memos->find($id); }

    /**
     * @param array<int, array<string, mixed>> $lines
     * @return array<int, array<string, mixed>>
     */
    private function allocateLines(array $lines, int $amount): array
    {
        $total = 0; foreach ($lines as $line) { $total += max(0, Arr::int($line, 'line_total_minor', Arr::int($line, 'qty') * Arr::int($line, 'unit_price_minor'))); }
        if ($total <= 0) return [];
        $out = []; $allocated = 0; $last = count($lines) - 1;
        foreach (array_values($lines) as $index => $line) { $base = max(0, Arr::int($line, 'line_total_minor', Arr::int($line, 'qty') * Arr::int($line, 'unit_price_minor'))); $share = $index === $last ? $amount - $allocated : intdiv($amount * $base, $total); $allocated += $share; $out[] = ['source_line_id' => Arr::str($line, 'line_id', Arr::str($line, 'id')), 'sku' => Arr::str($line, 'sku'), 'name' => Arr::str($line, 'name'), 'amount_minor' => $share]; }
        return $out;
    }

    private function allocateTax(int $tax, int $amount, int $total): int { return $total > 0 ? intdiv($tax * $amount, $total) : 0; }

    /** @param array<int, mixed> $taxes */
    private function allocateTaxes(array $taxes, int $amount, int $total): array
    {
        $out = [];
        foreach ($taxes as $tax) if (is_array($tax)) {
            $row = Arr::row($tax);
            $row['taxable_minor'] = $this->allocateTax(Arr::int($row, 'taxable_minor'), $amount, $total);
            $row['tax_minor'] = $this->allocateTax(Arr::int($row, 'tax_minor'), $amount, $total);
            $out[] = ProviderEvidence::redact($row);
        }
        return $out;
    }
}
