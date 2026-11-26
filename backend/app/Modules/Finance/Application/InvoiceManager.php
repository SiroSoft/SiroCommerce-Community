<?php

declare(strict_types=1);

namespace App\Modules\Finance\Application;

use App\Modules\Finance\Contracts\InvoiceService;
use App\Modules\Finance\Contracts\InvoiceStore;
use App\Support\Arr;
use App\Support\Ids;
use Siro\Core\Audit;
use App\Modules\Shared\Domain\Currency;
use App\Support\ProviderEvidence;
use App\Modules\Shared\Domain\Money;

final class InvoiceManager implements InvoiceService
{
    public function __construct(private readonly InvoiceStore $store) {}

    public function create(string $orderId, string $paymentId, string $idempotencyKey): array
    {
        if ($idempotencyKey === '') throw new \InvalidArgumentException('Idempotency-Key is required');
        $old = $this->store->byIdempotency($idempotencyKey);
        if ($old !== null) {
            if (Arr::str($old, 'order_id') !== $orderId || Arr::str($old, 'payment_id') !== $paymentId) throw new \InvalidArgumentException('Invoice idempotency key was reused');
            return $old;
        }
        $evidence = $this->store->evidence($orderId, $paymentId);
        $order = Arr::row($evidence['order']);
        $payment = Arr::row($evidence['payment']);
        if ($order === [] || $payment === []) throw new \InvalidArgumentException('Order and payment evidence are required');
        $currency = Arr::str($order, 'currency', 'VND');
        $currency = Currency::normalize($currency);
        if (Currency::normalize(Arr::str($payment, 'currency', $currency)) !== $currency) throw new \InvalidArgumentException('Order and payment currencies do not match');
        if (Arr::int($order, 'minor_unit', Currency::minorUnit($currency)) !== Currency::minorUnit($currency) || Arr::int($payment, 'minor_unit', Currency::minorUnit($currency)) !== Currency::minorUnit($currency)) throw new \InvalidArgumentException('Currency minor unit is invalid');
        $rawLines = is_array($evidence['lines'] ?? null) ? $evidence['lines'] : [];
        $lines = [];
        foreach ($rawLines as $line) if (is_array($line)) {
            $line = Arr::row($line);
            $qty = Arr::int($line, 'qty');
            $unit = Arr::int($line, 'unit_price_minor');
            if ($qty <= 0 || $unit < 0) throw new \InvalidArgumentException('Invoice line evidence is invalid');
            $calculated = (new Money($unit, $currency))->multiply($qty)->amountMinor;
            if (isset($line['line_total_minor']) && Arr::int($line, 'line_total_minor') !== $calculated) throw new \InvalidArgumentException('Invoice line total evidence is invalid');
            $line['amount_minor'] = $calculated;
            $lines[] = ProviderEvidence::redact($line);
        }
        $taxEvidence = is_array($evidence['tax'] ?? null) ? $evidence['tax'] : [];
        $total = Arr::int($order, 'grand_total_minor');
        Currency::assertAmount($currency, $total);
        if ($total !== Arr::int($order, 'subtotal_minor') - Arr::int($order, 'discount_minor') + Arr::int($order, 'shipping_minor') + Arr::int($order, 'tax_minor')) throw new \InvalidArgumentException('Order totals do not reconcile');
        if (Arr::int($order, 'tax_minor') > 0 && $taxEvidence === []) throw new \InvalidArgumentException('Tax evidence is required');
        if (Arr::int($payment, 'amount_minor') !== Arr::int($order, 'grand_total_minor') || Arr::str($payment, 'currency', $currency) !== $currency) throw new \InvalidArgumentException('Payment evidence does not match invoice total');
        $taxEvidence = ProviderEvidence::redact($taxEvidence);
        $data = ['id' => Ids::new(), 'number' => 'INV-' . strtoupper(substr(Ids::new(), -10)), 'order_id' => $orderId, 'payment_id' => $paymentId, 'state' => 'draft', 'currency' => $currency, 'minor_unit' => Currency::minorUnit($currency), 'subtotal_minor' => Arr::int($order, 'subtotal_minor'), 'discount_minor' => Arr::int($order, 'discount_minor'), 'shipping_minor' => Arr::int($order, 'shipping_minor'), 'tax_minor' => Arr::int($order, 'tax_minor'), 'amount_minor' => $total, 'evidence_json' => json_encode(ProviderEvidence::redact(['order' => $order, 'payment' => $payment, 'lines' => $lines, 'tax' => $taxEvidence]), JSON_THROW_ON_ERROR), 'line_snapshots' => $lines, 'tax_snapshots' => $taxEvidence, 'idempotency_key' => $idempotencyKey, 'created_at' => date('Y-m-d H:i:s')];
        try {
            $result = $this->store->create($data);
        } catch (\Throwable $e) {
            $existing = $this->store->byIdempotency($idempotencyKey);
            if ($existing !== null) return $existing;
            throw $e;
        }
        Audit::log('commerce.invoice.created', ['invoice_id' => Arr::requiredStr($result, 'id'), 'order_id' => $orderId, 'payment_id' => $paymentId]);
        return $result;
    }

    public function find(string $id): ?array { return $this->store->find($id); }

    public function issue(string $id, string $idempotencyKey): array
    {
        if ($idempotencyKey === '') throw new \InvalidArgumentException('Idempotency-Key is required');
        $row = $this->store->find($id);
        if ($row === null) throw new \RuntimeException('Invoice not found');
        if (in_array(Arr::str($row, 'state'), ['issued', 'paid'], true)) return $row;
        if (Arr::str($row, 'state') !== 'draft') throw new \InvalidArgumentException('Invoice cannot be issued');
        $evidence = json_decode(Arr::str($row, 'evidence_json'), true);
        $payment = is_array($evidence) ? Arr::row($evidence['payment'] ?? []) : [];
        if (!in_array(Arr::str($payment, 'state'), ['captured', 'paid'], true)) throw new \InvalidArgumentException('Invoice cannot be issued before payment capture');
        if (isset($payment['captured_amount_minor']) && Arr::int($payment, 'captured_amount_minor') !== Arr::int($row, 'amount_minor')) throw new \InvalidArgumentException('Captured payment amount does not match invoice total');
        $this->store->setState($id, 'issued', date('Y-m-d H:i:s'));
        $updated = $this->store->find($id);
        if ($updated === null) throw new \RuntimeException('Invoice lost after issue');
        Audit::log('commerce.invoice.issued', ['invoice_id' => $id]);
        return $updated;
    }
}
