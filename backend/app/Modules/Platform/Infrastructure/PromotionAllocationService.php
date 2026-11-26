<?php
declare(strict_types=1);
namespace App\Modules\Platform\Infrastructure;
use App\Models\Order;
use App\Models\PromotionComponentAllocation;
use App\Models\OrderLine;
use App\Modules\Shared\Domain\Currency;
use App\Support\Arr;
use App\Support\Ids;
use Siro\Core\Database;
final class PromotionAllocationService
{
    /** @param array<int,array<string,mixed>> $allocations
     * @return array<string,mixed> */
    public function reconcile(string $orderId, array $allocations, string $key): array
    {
        $key = trim($key); if ($key === '' || $allocations === []) throw new \InvalidArgumentException('Promotion allocation contract is invalid');
        $result = Database::transaction(function () use ($orderId, $allocations, $key): mixed {
            $order = $this->lockedOrder($orderId);
            if (!$order instanceof Order) throw new \RuntimeException('Order not found');
            $orderData = Arr::row($order); $currency = Currency::normalize(Arr::str($orderData, 'currency', 'VND'));
            $expected = Arr::int($orderData, 'discount_minor'); $total = 0; $normalized = [];
            foreach ($allocations as $allocation) {
                $amount = Arr::int($allocation, 'discount_minor', -1); $promotion = trim(Arr::str($allocation, 'promotion_id'));
                if ($amount < 0 || $promotion === '') throw new \InvalidArgumentException('Promotion allocation is invalid');
                $lineId = Arr::nullableStr($allocation, 'line_id'); $componentId = Arr::nullableStr($allocation, 'component_id');
                if ($componentId !== null) {
                    if ($lineId === null) throw new \InvalidArgumentException('Component allocation requires an order line');
                    $line = OrderLine::query()->where('id', $lineId)->where('order_id', $orderId)->first();
                    if ($line === null) throw new \InvalidArgumentException('Promotion allocation line is not part of the order');
                    $components = json_decode(Arr::str(Arr::row($line), 'components_json', '[]'), true);
                    $valid = false;
                    if (is_array($components)) foreach ($components as $component) if (is_array($component) && (Arr::str(Arr::row($component), 'component_id', Arr::str(Arr::row($component), 'variant_id')) === $componentId || Arr::str(Arr::row($component), 'component_variant_id') === $componentId)) { $valid = true; break; }
                    if (!$valid) throw new \InvalidArgumentException('Promotion allocation component is not in the order snapshot');
                }
                Currency::assertAmount($currency, $amount); $total += $amount;
                $normalized[] = ['line_id' => $lineId, 'component_id' => $componentId, 'promotion_id' => $promotion, 'discount_minor' => $amount];
            }
            if ($total !== $expected) throw new \InvalidArgumentException('Promotion allocation does not reconcile to order discount');
            $existing = PromotionComponentAllocation::query()->where('idempotency_key', $key)->first();
            if ($existing !== null) {
                $row = Arr::row($existing);
                $storedEvidence = json_decode(Arr::str($row, 'evidence_json', '{}'), true);
                $storedAllocations = is_array($storedEvidence) && is_array($storedEvidence['allocations'] ?? null) ? $storedEvidence['allocations'] : null;
                if (Arr::str($row, 'order_id') !== $orderId || Arr::str($row, 'currency') !== $currency || $storedAllocations !== $normalized) throw new \InvalidArgumentException('Idempotency key was reused');
                return $row;
            }
            $first = $normalized[0];
            $row = new PromotionComponentAllocation(); $row->fill(['id' => Ids::new(), 'order_id' => $orderId, 'line_id' => $first['line_id'], 'component_id' => $first['component_id'], 'promotion_id' => $first['promotion_id'], 'currency' => $currency, 'minor_unit' => Currency::minorUnit($currency), 'discount_minor' => $total, 'idempotency_key' => $key, 'evidence_json' => json_encode(['order_total_minor' => Arr::int($orderData, 'grand_total_minor'), 'allocations' => $normalized], JSON_THROW_ON_ERROR), 'created_at' => date('Y-m-d H:i:s')]); $row->save();
            return ['order_id' => $orderId, 'currency' => $currency, 'minor_unit' => Currency::minorUnit($currency), 'discount_minor' => $total, 'allocations' => $normalized, 'idempotency_key' => $key, 'representative_id' => Arr::str(Arr::row($row), 'id')];
        });
        if (!is_array($result)) throw new \RuntimeException('Promotion reconciliation returned invalid data');
        return Arr::row($result);
    }

    private function lockedOrder(string $orderId): mixed
    {
        return Order::query()->where('id', $orderId)->lockForUpdate()->first();
    }
}
