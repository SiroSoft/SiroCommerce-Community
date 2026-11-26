<?php
declare(strict_types=1);

namespace App\Modules\Platform\Infrastructure;

use App\Models\CouponUsage;
use App\Models\Order;
use App\Models\PromotionUsageReconciliation;
use App\Modules\Promotion\Contracts\PromotionUsageReconciler;
use App\Support\Arr;
use App\Support\Ids;
use App\Repositories\Concerns\CreatesModels;
use App\Modules\Shared\Domain\Currency;
use Siro\Core\Database;

final class PromotionUsageReconciliationAdapter implements PromotionUsageReconciler
{
    use CreatesModels;

    /** @return array<string, mixed> */
    public function reconcile(string $orderId, int $amountMinor, int $orderTotalMinor, string $currency, string $idempotencyKey, string $reason = ''): array
    {
        if ($amountMinor <= 0 || $orderTotalMinor <= 0 || $amountMinor > $orderTotalMinor || $idempotencyKey === '') throw new \InvalidArgumentException('Promotion reconciliation is invalid');
        $currency = Currency::normalize($currency);
        Currency::assertAmount($currency, $amountMinor);
        Currency::assertAmount($currency, $orderTotalMinor);
        try {
            $result = Database::transaction(function () use ($orderId, $amountMinor, $orderTotalMinor, $currency, $idempotencyKey, $reason): array {
            $existing = PromotionUsageReconciliation::query()->where('idempotency_key', $idempotencyKey)->first();
            if ($existing !== null) {
                $existingRow = Arr::row($existing);
                $existingEvidence = json_decode(Arr::str($existingRow, 'evidence_json', '{}'), true);
                $existingAmount = is_array($existingEvidence) ? Arr::int($this->normalize($existingEvidence), 'amount_minor', -1) : -1;
                if (Arr::str($existingRow, 'order_id') !== $orderId || Arr::str($existingRow, 'currency') !== $currency || $existingAmount !== $amountMinor) {
                    throw new \InvalidArgumentException('Promotion reconciliation idempotency key was reused');
                }
                unset($existingRow['minor_unit']);
                return $existingRow;
            }
            $order = Order::query()->where('id', $orderId)->lockForUpdate()->first();
            if ($order === null) throw new \RuntimeException('Order not found for promotion reconciliation');
            $orderRow = Arr::row($order);
            $storedCurrency = Currency::normalize(Arr::str($orderRow, 'currency', 'VND'));
            if (strtoupper($currency) !== $storedCurrency) throw new \InvalidArgumentException('Promotion reconciliation currency mismatch');
            if ($orderTotalMinor !== Arr::int($orderRow, 'grand_total_minor')) throw new \InvalidArgumentException('Promotion reconciliation total mismatch');
            $storedMinorUnit = Arr::int($orderRow, 'minor_unit', -1);
            // Older orders use the schema default 0 as "not recorded"; non-zero values are authoritative.
            if ($storedMinorUnit > 0 && $storedMinorUnit !== Currency::minorUnit($currency)) throw new \InvalidArgumentException('Promotion reconciliation minor unit mismatch');
            $evidence = json_decode(Arr::str($orderRow, 'promotion_evidence_json', '[]'), true);
            if (!is_array($evidence)) throw new \RuntimeException('Promotion evidence is invalid');
            $created = [];
            foreach (CouponUsage::query()->where('order_id', $orderId)->where('state', 'committed')->lockForUpdate()->get() as $usage) {
                $row = Arr::row($usage);
                 $alreadyReleased = 0;
                 $alreadyRefunded = 0;
                foreach (PromotionUsageReconciliation::query()->where('order_id', $orderId)->where('coupon_id', Arr::requiredStr($row, 'coupon_id'))->get() as $prior) {
                    $priorRow = Arr::row($prior);
                    $alreadyReleased += Arr::int($priorRow, 'released_discount_minor');
                    $priorEvidence = json_decode(Arr::str($priorRow, 'evidence_json', '{}'), true);
                     if (is_array($priorEvidence)) $alreadyRefunded += Arr::int($this->normalize($priorEvidence), 'amount_minor');
                 }
                 // The usage row stores the remaining reservation after reconciliation.
                 // Add released history back to recover the immutable checkout discount.
                 $originalDiscount = Arr::int($row, 'discount_minor') + $alreadyReleased;
                $cumulativeRefunded = min($orderTotalMinor, $alreadyRefunded + $amountMinor);
                $targetReleased = $cumulativeRefunded === $orderTotalMinor
                    ? $originalDiscount
                    : intdiv($originalDiscount * $cumulativeRefunded, $orderTotalMinor);
                $released = max(0, min($originalDiscount, $targetReleased - $alreadyReleased));
                if ($released <= 0) continue;
                $remaining = max(0, $originalDiscount - $alreadyReleased - $released);
                $usageId = Arr::nullableStr($row, 'id');
                $lockedUsage = $usageId === null ? null : CouponUsage::find($usageId);
                if (!$lockedUsage instanceof CouponUsage) throw new \RuntimeException('Promotion usage record is unavailable');
                $lockedUsage->fill(['discount_minor' => $remaining, 'state' => $remaining === 0 ? 'rolled_back' : 'committed', 'updated_at' => date('Y-m-d H:i:s')]);
                $lockedUsage->save();
                $created[] = ['coupon_id' => Arr::requiredStr($row, 'coupon_id'), 'released_discount_minor' => $released];
            }
            $releasedTotal = 0;
            foreach ($created as $item) $releasedTotal += $item['released_discount_minor'];
            $data = ['id' => Ids::new(), 'coupon_id' => $created[0]['coupon_id'] ?? null, 'order_id' => $orderId, 'idempotency_key' => $idempotencyKey, 'released_discount_minor' => $releasedTotal, 'currency' => $storedCurrency, 'minor_unit' => Currency::minorUnit($storedCurrency), 'reason' => $reason, 'evidence_json' => json_encode(['promotion' => $evidence, 'amount_minor' => $amountMinor, 'order_total_minor' => $orderTotalMinor, 'minor_unit' => Currency::minorUnit($storedCurrency), 'released' => $created], JSON_THROW_ON_ERROR), 'created_at' => date('Y-m-d H:i:s')];
            $createdRow = self::createRow(PromotionUsageReconciliation::class, $data);
            unset($createdRow['minor_unit']);
            return $createdRow;
            });
            if (!is_array($result)) throw new \RuntimeException('Promotion reconciliation returned invalid data');
            $normalized = [];
            foreach ($result as $key => $value) if (is_string($key)) $normalized[$key] = $value;
            return $normalized;
        } catch (\Throwable $e) {
            $existing = PromotionUsageReconciliation::query()->where('idempotency_key', $idempotencyKey)->first();
            if ($existing !== null) {
                $row = Arr::row($existing);
                $evidence = json_decode(Arr::str($row, 'evidence_json', '{}'), true);
                $existingAmount = is_array($evidence) ? Arr::int($this->normalize($evidence), 'amount_minor', -1) : -1;
                if (Arr::str($row, 'order_id') === $orderId && Arr::str($row, 'currency') === $currency && $existingAmount === $amountMinor) {
                    unset($row['minor_unit']);
                    return $row;
                }
            }
            throw $e;
        }
    }

    /** @return array<string, mixed> */
    private function normalize(mixed $value): array
    {
        $normalized = [];
        if (is_array($value)) foreach ($value as $key => $item) if (is_string($key)) $normalized[$key] = $item;
        return $normalized;
    }

}
