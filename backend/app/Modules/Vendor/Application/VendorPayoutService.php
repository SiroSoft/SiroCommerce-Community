<?php

declare(strict_types=1);

namespace App\Modules\Vendor\Application;

use App\Models\Order;
use App\Models\OrderLine;
use App\Models\Vendor;
use App\Modules\Vendor\Domain\VendorCommission;
use App\Modules\Vendor\Domain\VendorPayoutConflict;
use App\Modules\Vendor\Domain\VendorPayoutNotFound;
use App\Repositories\VendorLedgerRepository;
use App\Support\Arr;
use App\Support\Ids;
use Siro\Core\Database;

/**
 * Vendor commission ledger and manual payout batches.
 *
 * Snapshot rules: order_lines carry the vendor and commission at placement;
 * the ledger never re-reads the vendor commission_bps for sales. Sales and
 * refunds are idempotent by a deterministic key, and payouts move only
 * available entries in the requested window.
 */
final class VendorPayoutService
{
    public function __construct(private readonly ?VendorLedgerRepository $ledger = null)
    {
    }

    public function recordSalesForOrder(string $orderId): int
    {
        $order = Order::find($orderId);
        if ($order === null) return 0;
        $orderRow = $order->toArray();
        $currency = Arr::str($orderRow, 'currency', 'VND');
        $created = 0;
        foreach (OrderLine::query()->where('order_id', $orderId)->get() as $model) {
            $line = Arr::row($model);
            $vendorId = Arr::nullableStr($line, 'vendor_id');
            if ($vendorId === null) continue;
            $gross = Arr::int($line, 'qty') * Arr::int($line, 'unit_price_minor');
            if ($gross <= 0) continue;
            $commission = $this->lineCommission($line, $gross);
            $key = 'sale:' . $orderId . ':' . Arr::requiredStr($line, 'id');
            if ($this->ledgerRepo()->findByIdempotency($key) !== null) continue;
            $this->ledgerRepo()->createEntry([
                'id' => Ids::new(),
                'vendor_id' => $vendorId,
                'order_id' => $orderId,
                'order_line_id' => Arr::requiredStr($line, 'id'),
                'type' => 'sale',
                'gross_minor' => $gross,
                'commission_minor' => $commission,
                'net_minor' => $gross - $commission,
                'currency' => $currency,
                'state' => 'available',
                'payout_id' => null,
                'idempotency_key' => $key,
                'created_at' => date('Y-m-d H:i:s'),
            ]);
            $created++;
        }
        return $created;
    }

    /**
     * @param array<int, array<string, mixed>> $allocation
     */
    public function recordRefund(string $orderId, int $amountMinor, string $idempotencyKey, array $allocation = []): int
    {
        if ($amountMinor <= 0 || $idempotencyKey === '') return 0;
        if (!$this->ledgerRepo()->hasSaleForOrder($orderId)) return 0;
        $order = Order::find($orderId);
        if ($order === null) return 0;
        $orderRow = $order->toArray();
        $currency = Arr::str($orderRow, 'currency', 'VND');
        $lines = [];
        foreach (OrderLine::query()->where('order_id', $orderId)->get() as $model) {
            $row = Arr::row($model);
            if (Arr::nullableStr($row, 'vendor_id') !== null) $lines[Arr::requiredStr($row, 'id')] = $row;
        }
        if ($lines === []) return 0;
        $created = 0;
        if ($allocation !== []) {
            foreach ($allocation as $allocationLine) {
                $lineId = Arr::str($allocationLine, 'order_line_id');
                $qty = Arr::int($allocationLine, 'qty');
                if ($lineId === '' || $qty <= 0 || !isset($lines[$lineId])) continue;
                $line = $lines[$lineId];
                $lineQty = max(1, Arr::int($line, 'qty'));
                $lineGross = $lineQty * Arr::int($line, 'unit_price_minor');
                if ($lineGross <= 0) continue;
                $refundGross = min($qty, $lineQty) * Arr::int($line, 'unit_price_minor');
                if ($refundGross <= 0) continue;
                $lineCommission = $this->lineCommission($line, $lineGross);
                $refundCommission = intdiv($lineCommission * $refundGross + intdiv($lineGross, 2), $lineGross);
                $key = 'refund:' . $idempotencyKey . ':line:' . $lineId;
                if ($this->ledgerRepo()->findByIdempotency($key) !== null) continue;
                $this->ledgerRepo()->createEntry([
                    'id' => Ids::new(),
                    'vendor_id' => Arr::requiredStr($line, 'vendor_id'),
                    'order_id' => $orderId,
                    'order_line_id' => $lineId,
                    'type' => 'refund',
                    'gross_minor' => -$refundGross,
                    'commission_minor' => -$refundCommission,
                    'net_minor' => -$refundGross + $refundCommission,
                    'currency' => $currency,
                    'state' => 'available',
                    'payout_id' => null,
                    'idempotency_key' => $key,
                    'created_at' => date('Y-m-d H:i:s'),
                ]);
                $created++;
            }
            return $created;
        }
        // Order-level refund: distribute the refund across the vendors that
        // own the order lines, proportionally to their line value.
        $vendorTotals = [];
        foreach ($lines as $line) {
            $vendorId = Arr::requiredStr($line, 'vendor_id');
            $gross = Arr::int($line, 'qty') * Arr::int($line, 'unit_price_minor');
            if ($gross <= 0) continue;
            $vendorTotals[$vendorId] ??= ['gross' => 0, 'commission' => 0];
            $vendorTotals[$vendorId]['gross'] += $gross;
            $vendorTotals[$vendorId]['commission'] += $this->lineCommission($line, $gross);
        }
        $totalGross = array_sum(array_column($vendorTotals, 'gross'));
        if ($totalGross <= 0) return 0;
        $remainingAmount = $amountMinor;
        $lastVendor = array_key_last($vendorTotals);
        foreach ($vendorTotals as $vendorId => $totals) {
            $share = $vendorId === $lastVendor
                ? $remainingAmount
                : intdiv($amountMinor * $totals['gross'], $totalGross);
            if ($share <= 0) continue;
            $vendorCommission = intdiv($totals['commission'] * $share + intdiv($totals['gross'], 2), $totals['gross']);
            $key = 'refund:' . $idempotencyKey . ':vendor:' . $vendorId;
            if ($this->ledgerRepo()->findByIdempotency($key) === null) {
                $this->ledgerRepo()->createEntry([
                    'id' => Ids::new(),
                    'vendor_id' => $vendorId,
                    'order_id' => $orderId,
                    'order_line_id' => null,
                    'type' => 'refund',
                    'gross_minor' => -$share,
                    'commission_minor' => -$vendorCommission,
                    'net_minor' => -$share + $vendorCommission,
                    'currency' => $currency,
                    'state' => 'available',
                    'payout_id' => null,
                    'idempotency_key' => $key,
                    'created_at' => date('Y-m-d H:i:s'),
                ]);
                $created++;
            }
            $remainingAmount -= $share;
        }
        return $created;
    }

    /**
     * @param array{state?: string, from?: string, to?: string} $filters
     * @return array{entries: array<int, array<string, mixed>>, totals: array<string, int>}
     */
    public function ledger(string $vendorId, array $filters = []): array
    {
        $this->assertVendor($vendorId);
        $state = $filters['state'] ?? null;
        if ($state !== null && $state !== '' && !in_array($state, ['pending', 'available', 'paid', 'void'], true)) {
            throw new \InvalidArgumentException('state must be pending, available, paid, or void');
        }
        $from = $this->normalizeDate($filters['from'] ?? null, false);
        $to = $this->normalizeDate($filters['to'] ?? null, true);
        return [
            'entries' => $this->ledgerRepo()->entries($vendorId, $state === '' ? null : $state, $from, $to),
            'totals' => $this->ledgerRepo()->totals($vendorId),
        ];
    }

    /** @return array{gross: int, commission: int, net: int, pending: int, available: int, paid: int} */
    public function balances(string $vendorId): array
    {
        $this->assertVendor($vendorId);
        return $this->ledgerRepo()->totals($vendorId);
    }

    /** @return array<int, array<string, mixed>> */
    public function payouts(string $vendorId): array
    {
        $this->assertVendor($vendorId);
        return $this->ledgerRepo()->payouts($vendorId);
    }

    /** @return array<string, mixed> */
    public function createPayout(string $vendorId, string $periodStart, string $periodEnd, string $idempotencyKey): array
    {
        $this->assertVendor($vendorId);
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$/', $idempotencyKey) !== 1) {
            throw new \InvalidArgumentException('A valid Idempotency-Key is required');
        }
        $start = $this->normalizeDate($periodStart, false);
        $end = $this->normalizeDate($periodEnd, true);
        if ($start === null || $end === null) throw new \InvalidArgumentException('period_start and period_end are required');
        if ($start > $end) throw new \InvalidArgumentException('period_start must be before period_end');
        $existing = $this->ledgerRepo()->findPayoutByIdempotency($idempotencyKey);
        if ($existing !== null) {
            if (Arr::str($existing, 'vendor_id') !== $vendorId) throw new VendorPayoutConflict('Idempotency key conflicts with another vendor payout');
            return $existing;
        }
        /** @var array<string, mixed> $result */
        $result = Database::transaction(function () use ($vendorId, $start, $end, $idempotencyKey): array {
            $existing = $this->ledgerRepo()->findPayoutByIdempotency($idempotencyKey);
            if ($existing !== null) {
                if (Arr::str($existing, 'vendor_id') !== $vendorId) throw new VendorPayoutConflict('Idempotency key conflicts with another vendor payout');
                return $existing;
            }
            $entries = $this->ledgerRepo()->availableInWindow($vendorId, $start, $end);
            if ($entries === []) throw new \InvalidArgumentException('No available ledger entries in the payout window');
            $amount = 0;
            $currency = null;
            $entryIds = [];
            foreach ($entries as $entry) {
                $entryCurrency = Arr::str($entry, 'currency', 'VND');
                if ($currency === null) $currency = $entryCurrency;
                if ($currency !== $entryCurrency) throw new \InvalidArgumentException('Payout window contains mixed currencies');
                $amount += Arr::int($entry, 'net_minor');
                $entryIds[] = Arr::requiredStr($entry, 'id');
            }
            if ($amount <= 0) throw new \InvalidArgumentException('Payout amount must be positive');
            $payout = $this->ledgerRepo()->createPayout([
                'id' => Ids::new(),
                'vendor_id' => $vendorId,
                'amount_minor' => $amount,
                'currency' => $currency,
                'state' => 'pending',
                'period_start' => $start,
                'period_end' => $end,
                'entries_count' => count($entryIds),
                'idempotency_key' => $idempotencyKey,
                'created_at' => date('Y-m-d H:i:s'),
                'paid_at' => null,
                'cancelled_at' => null,
            ]);
            $this->ledgerRepo()->assignEntries($entryIds, Arr::requiredStr($payout, 'id'), 'pending');
            return $payout;
        });
        return $result;
    }

    /** @return array<string, mixed> */
    public function markPaid(string $payoutId, ?string $providerReference = null): array
    {
        $payout = $this->ledgerRepo()->findPayout($payoutId);
        if ($payout === null) throw new VendorPayoutNotFound('Vendor payout not found');
        if (Arr::str($payout, 'state') !== 'pending') throw new VendorPayoutConflict('Only pending payouts can be marked paid');
        /** @var array<string, mixed> $result */
        $result = Database::transaction(function () use ($payoutId, $payout, $providerReference): array {
            $data = ['state' => 'paid', 'paid_at' => date('Y-m-d H:i:s')];
            if ($providerReference !== null && $providerReference !== '') $data['provider_reference'] = $providerReference;
            $this->ledgerRepo()->updatePayout($payoutId, $data);
            foreach ($this->ledgerRepo()->entriesByPayout($payoutId) as $entry) {
                $this->ledgerRepo()->assignEntries([Arr::requiredStr($entry, 'id')], $payoutId, 'paid');
            }
            return $this->ledgerRepo()->findPayout($payoutId) ?? $payout;
        });
        return $result;
    }

    /** @return array<string, mixed> */
    public function cancel(string $payoutId): array
    {
        $payout = $this->ledgerRepo()->findPayout($payoutId);
        if ($payout === null) throw new VendorPayoutNotFound('Vendor payout not found');
        if (Arr::str($payout, 'state') !== 'pending') throw new VendorPayoutConflict('Only pending payouts can be cancelled');
        /** @var array<string, mixed> $result */
        $result = Database::transaction(function () use ($payoutId, $payout): array {
            $entryIds = [];
            foreach ($this->ledgerRepo()->entriesByPayout($payoutId) as $entry) {
                $entryIds[] = Arr::requiredStr($entry, 'id');
            }
            $this->ledgerRepo()->updatePayout($payoutId, ['state' => 'cancelled', 'cancelled_at' => date('Y-m-d H:i:s')]);
            $this->ledgerRepo()->releaseEntries($entryIds);
            return $this->ledgerRepo()->findPayout($payoutId) ?? $payout;
        });
        return $result;
    }

    /** @return array<string, mixed> */
    public function markFailed(string $payoutId, ?string $reason = null, ?string $reference = null): array
    {
        $payout = $this->ledgerRepo()->findPayout($payoutId);
        if ($payout === null) throw new VendorPayoutNotFound('Vendor payout not found');
        if (Arr::str($payout, 'state') !== 'pending') throw new VendorPayoutConflict('Only pending payouts can fail');
        /** @var array<string, mixed> $result */
        $result = Database::transaction(function () use ($payoutId, $payout, $reason, $reference): array {
            $entryIds = [];
            foreach ($this->ledgerRepo()->entriesByPayout($payoutId) as $entry) $entryIds[] = Arr::requiredStr($entry, 'id');
            $data = ['state' => 'failed', 'failed_at' => date('Y-m-d H:i:s')];
            if ($reason !== null && $reason !== '') $data['failure_reason'] = mb_substr($reason, 0, 191);
            if ($reference !== null && $reference !== '') $data['provider_reference'] = mb_substr($reference, 0, 191);
            $this->ledgerRepo()->updatePayout($payoutId, $data);
            $this->ledgerRepo()->releaseEntries($entryIds);
            return $this->ledgerRepo()->findPayout($payoutId) ?? $payout;
        });
        return $result;
    }

    /** @return array<string, mixed> */
    public function markReturned(string $payoutId, ?string $reason = null, ?string $reference = null): array
    {
        $payout = $this->ledgerRepo()->findPayout($payoutId);
        if ($payout === null) throw new VendorPayoutNotFound('Vendor payout not found');
        if (Arr::str($payout, 'state') !== 'paid') throw new VendorPayoutConflict('Only paid payouts can be returned');
        /** @var array<string, mixed> $result */
        $result = Database::transaction(function () use ($payoutId, $payout, $reason, $reference): array {
            $entryIds = [];
            foreach ($this->ledgerRepo()->entriesByPayout($payoutId) as $entry) $entryIds[] = Arr::requiredStr($entry, 'id');
            $data = ['state' => 'returned', 'returned_at' => date('Y-m-d H:i:s')];
            if ($reason !== null && $reason !== '') $data['failure_reason'] = mb_substr($reason, 0, 191);
            if ($reference !== null && $reference !== '') $data['provider_reference'] = mb_substr($reference, 0, 191);
            $this->ledgerRepo()->updatePayout($payoutId, $data);
            $this->ledgerRepo()->releaseEntries($entryIds);
            return $this->ledgerRepo()->findPayout($payoutId) ?? $payout;
        });
        return $result;
    }

    /** @return array<string, mixed> */
    public function payout(string $payoutId): array
    {
        $payout = $this->ledgerRepo()->findPayout($payoutId);
        if ($payout === null) throw new VendorPayoutNotFound('Vendor payout not found');
        return $payout;
    }

    private function ledgerRepo(): VendorLedgerRepository
    {
        return $this->ledger ?? new VendorLedgerRepository();
    }

    /** @param array<string, mixed> $line */
    private function lineCommission(array $line, int $gross): int
    {
        $snapshot = $line['vendor_commission_minor'] ?? null;
        if (is_numeric($snapshot)) return max(0, (int) $snapshot);
        $vendorId = Arr::nullableStr($line, 'vendor_id');
        if ($vendorId === null) return 0;
        $vendor = Vendor::find($vendorId);
        $bps = $vendor === null ? 0 : Arr::int(Arr::row($vendor), 'commission_bps');
        return VendorCommission::minor($gross, $bps);
    }

    private function assertVendor(string $vendorId): void
    {
        if (Vendor::find($vendorId) === null) throw new VendorPayoutNotFound('Vendor not found');
    }

    private function normalizeDate(mixed $value, bool $endOfDay): ?string
    {
        if (!is_string($value) || trim($value) === '') return null;
        $raw = trim($value);
        $timestamp = strtotime($raw);
        if ($timestamp === false) throw new \InvalidArgumentException('Invalid date');
        if ($endOfDay && preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw) === 1) {
            return date('Y-m-d 23:59:59', $timestamp);
        }
        return date('Y-m-d H:i:s', $timestamp);
    }
}
