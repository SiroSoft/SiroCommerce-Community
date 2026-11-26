<?php
declare(strict_types=1);
namespace App\Modules\Platform\Infrastructure;

use App\Models\CreditMemo;
use App\Models\Invoice;
use App\Models\Refund;
use App\Modules\Finance\Contracts\CreditMemoStore;
use App\Repositories\Concerns\CreatesModels;
use App\Support\Arr;
use App\Support\Ids;
use Siro\Core\Database;

final class CreditMemoStoreAdapter implements CreditMemoStore
{
    use CreatesModels;
    public function byIdempotency(string $key): ?array { $m = CreditMemo::query()->where('idempotency_key', $key)->first(); return $m instanceof CreditMemo ? Arr::row($m) : null; }
    public function find(string $id): ?array { $m = CreditMemo::find($id); return $m instanceof CreditMemo ? Arr::row($m) : null; }
    public function create(array $data): array
    {
        $lines = is_array($data['line_snapshots'] ?? null) ? $data['line_snapshots'] : [];
        $taxes = is_array($data['tax_snapshots'] ?? null) ? $data['tax_snapshots'] : [];
        unset($data['line_snapshots'], $data['tax_snapshots']);
        return Database::transaction(function () use ($data, $lines, $taxes): array {
            $result = self::createRow(CreditMemo::class, $data);
            foreach ($lines as $line) if (is_array($line)) self::createRow(\App\Models\CreditMemoLine::class, array_merge(Arr::row($line), ['id' => Ids::new(), 'credit_memo_id' => Arr::requiredStr($result, 'id')]));
            foreach ($taxes as $tax) if (is_array($tax)) self::createRow(\App\Models\CreditMemoTax::class, array_merge($this->taxRow($tax), ['id' => Ids::new(), 'credit_memo_id' => Arr::requiredStr($result, 'id')]));
            return $result;
        });
    }
    public function createRefund(array $data): void
    {
        Database::transaction(function () use ($data): void {
            $invoiceId = Arr::str($data, 'invoice_id');
            $invoice = Invoice::query()->where('id', $invoiceId)->lockForUpdate()->first();
            if ($invoice === null) throw new \RuntimeException('Invoice not found');
            $key = Arr::str($data, 'idempotency_key');
            if ($key !== '' && Refund::query()->where('idempotency_key', $key)->first() !== null) return;
            $reserved = 0; $linked = [];
            foreach (CreditMemo::query()->where('invoice_id', $invoiceId)->whereIn('state', ['issued', 'pending'])->get() as $memo) { $row = Arr::row($memo); $reserved += Arr::int($row, 'amount_minor'); $refundId = Arr::nullableStr($row, 'refund_id'); if ($refundId !== null) $linked[$refundId] = true; }
            foreach (Refund::query()->where('payment_id', Arr::str($data, 'payment_id'))->whereIn('state', ['pending', 'done'])->get() as $refund) { $row = Arr::row($refund); if (!isset($linked[Arr::str($row, 'id')])) $reserved += Arr::int($row, 'amount_minor'); }
            if ($reserved + Arr::int($data, 'amount_minor') > Arr::int(Arr::row($invoice), 'amount_minor')) throw new \InvalidArgumentException('Credit memo exceeds invoice balance');
            unset($data['invoice_id']); self::createRow(Refund::class, $data);
        });
    }
    public function setRefundState(string $id, string $state): void { $m = Refund::find($id); if ($m !== null) { $m->setAttribute('state', $state); $m->save(); } }

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
