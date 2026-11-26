<?php
declare(strict_types=1);
namespace App\Modules\Platform\Infrastructure;
use App\Models\Coupon;
use App\Models\CouponUsage;
use App\Models\PromotionDecisionAudit;
use App\Models\PromotionCampaign;
use App\Models\PromotionSegmentMember;
use App\Modules\Promotion\Contracts\PromotionReader;
use App\Modules\Promotion\Contracts\PromotionUsageWriter;
use App\Support\Ids;
use Siro\Core\Database;
use App\Support\Arr;
final class PromotionStoreAdapter implements PromotionReader, PromotionUsageWriter
{
    /** @return array<string, mixed>|null */
    public function findActive(string $code): ?array { $normalized = strtoupper(trim($code)); $row = Coupon::query()->where('code', $normalized)->where('state', 'active')->first(); if ($row === null) return null; $out = Arr::row($row); $campaign = PromotionCampaign::query()->where('code', $normalized)->whereIn('state', ['active', 'scheduled'])->first(); if ($campaign !== null) { $campaignRow = Arr::row($campaign); $campaignRules = json_decode(Arr::str($campaignRow, 'rules', '{}'), true); if (is_array($campaignRules)) { $baseRules = json_decode(Arr::str($out, 'rules', '{}'), true); if (!is_array($baseRules)) $baseRules = []; $out['rules'] = json_encode(array_merge($baseRules, $campaignRules + ['campaign_state' => Arr::str($campaignRow, 'state', 'scheduled'), 'starts_at' => Arr::str($campaignRow, 'starts_at'), 'ends_at' => Arr::str($campaignRow, 'ends_at')]), JSON_THROW_ON_ERROR); } } return $out; }
    /** @return array<int, string> */
    public function segmentIds(string $customerId): array { $ids = []; foreach (PromotionSegmentMember::query()->where('customer_id', $customerId)->get() as $member) { $row = Arr::row($member); $id = Arr::str($row, 'segment_id'); if ($id !== '') $ids[] = $id; } return $ids; }
    public function usageCount(string $couponId): int { $count = 0; foreach (CouponUsage::query()->where('coupon_id', $couponId)->whereIn('state', ['reserved', 'committed'])->get() as $row) $count++; return $count; }
    public function usageCountForCustomer(string $couponId, string $customerId): int {
        if ($customerId === '') return 0;
        $count = 0;
        foreach (CouponUsage::query()->where('coupon_id', $couponId)->whereIn('state', ['reserved', 'committed'])->get() as $row) {
            $usage = Arr::row($row);
            $orderId = Arr::str($usage, 'order_id');
            if ($orderId === '') continue;
            $order = \Siro\Core\Database::table('orders')->where('id', $orderId)->first();
            if ($order !== null && Arr::str(Arr::row($order), 'customer_id') === $customerId) $count++;
        }
        return $count;
    }
    public function attemptCount(string $couponId, string $actorHash, int $windowSeconds): int { $since = date('Y-m-d H:i:s', time() - $windowSeconds); $count = 0; foreach (PromotionDecisionAudit::query()->where('coupon_id', $couponId)->where('actor_hash', $actorHash)->where('created_at', '>=', $since)->get() as $row) $count++; return $count; }
    public function record(string $couponId, string $orderId, int $discountMinor): void { $this->reserve($couponId, $orderId, $discountMinor); $this->commit($orderId); }
    public function reserve(string $couponId, string $reservationKey, int $discountMinor): void { if ($discountMinor < 0) throw new \InvalidArgumentException('Promotion discount cannot be negative'); Database::transaction(function () use ($couponId, $reservationKey, $discountMinor): void { $existing = CouponUsage::query()->where('reservation_key', $reservationKey)->first(); if ($existing !== null) { $row = Arr::row($existing); if (Arr::str($row, 'coupon_id') !== $couponId || Arr::int($row, 'discount_minor') !== $discountMinor) throw new \InvalidArgumentException('Promotion reservation key was reused'); return; } $coupon = Coupon::query()->where('id', $couponId)->where('state', 'active')->lockForUpdate()->first(); if ($coupon === null) throw new \RuntimeException('Promotion is no longer active'); $rules = json_decode(Arr::str(Arr::row($coupon), 'rules', '{}'), true); $rawLimit = is_array($rules) ? ($rules['usage_limit'] ?? $rules['max_uses'] ?? 0) : 0; $limit = is_numeric($rawLimit) ? (int) $rawLimit : 0; if ($limit > 0 && $this->usageCount($couponId) >= $limit) throw new \RuntimeException('Promotion usage limit reached'); CouponUsage::create(['id' => Ids::new(), 'coupon_id' => $couponId, 'order_id' => $reservationKey, 'discount_minor' => $discountMinor, 'reservation_key' => $reservationKey, 'state' => 'reserved', 'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')]); }); }
    public function commit(string $reservationKey): void { $this->setState($reservationKey, 'committed'); }
    public function rollback(string $reservationKey): void { $this->setState($reservationKey, 'rolled_back'); }
    private function setState(string $key, string $state): void { Database::transaction(function () use ($key, $state): void { $row = CouponUsage::query()->where('reservation_key', $key)->where('state', 'reserved')->lockForUpdate()->first(); $this->updateUsage($row, $state); }); }
    private function updateUsage(mixed $row, string $state): void { if (is_object($row) && method_exists($row, 'fill') && method_exists($row, 'save')) { $row->fill(['state' => $state, 'updated_at' => date('Y-m-d H:i:s')]); $row->save(); } }
}
