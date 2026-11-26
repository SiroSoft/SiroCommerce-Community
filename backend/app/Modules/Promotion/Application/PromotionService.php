<?php
declare(strict_types=1);
namespace App\Modules\Promotion\Application;
use App\Modules\Promotion\Contracts\PromotionReader;
use App\Modules\Promotion\Contracts\PromotionUsageWriter;
use App\Modules\Promotion\Contracts\PromotionDecisionAudit;
use App\Support\Arr;
final class PromotionService
{
    public function __construct(private readonly PromotionReader $reader, private readonly PromotionUsageWriter $usage, private readonly ?PromotionDecisionAudit $audit = null) {}
    /**
     * @param array<int, array<string, mixed>> $lines
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public function preview(string $code, int $subtotalMinor, string $currency = 'VND', array $lines = [], array $context = []): array
    {
        $originalCode = strtoupper(trim($code));
        try {
            /** @var array<string, mixed> $result */
            $result = $this->evaluate($originalCode, $subtotalMinor, $currency, $lines, $context);
        } catch (\Throwable $e) {
            $result = ['error' => 'Promotion evaluation failed'];
        }
        $this->audit($originalCode, $subtotalMinor, $currency, $context, $result);
        return $result;
    }

    /**
     * @param array<int, array<string, mixed>> $lines
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private function evaluate(string $code, int $subtotalMinor, string $currency = 'VND', array $lines = [], array $context = []): array
    {
        $code = strtoupper(trim($code));
        if ($code === '') return ['error' => 'Promotion code is required'];
        if ($subtotalMinor < 0) return ['error' => 'Promotion subtotal cannot be negative'];
        $currency = strtoupper(trim($currency));
        if (!preg_match('/^[A-Z]{3}$/', $currency)) return ['error' => 'Promotion currency is invalid'];
        $coupon = $this->reader->findActive($code);
        if ($coupon === null) return ['error' => 'Coupon not found or inactive'];
        $coupon = Arr::row($coupon);
        $raw = json_decode(Arr::str($coupon, 'rules', '{}'), true); /** @var array<string, mixed> $rules */ $rules = [];
        if (!is_array($raw)) return ['error' => 'Promotion rules are invalid'];
        foreach ($raw as $key => $value) if (is_string($key)) $rules[$key] = $value;
        $now = time();
        $starts = Arr::str($rules, 'starts_at', Arr::str($rules, 'start_at', Arr::str($rules, 'date_from')));
        $ends = Arr::str($rules, 'ends_at', Arr::str($rules, 'end_at', Arr::str($rules, 'date_to')));
        if (($starts !== '' && strtotime($starts) === false) || ($ends !== '' && strtotime($ends) === false)) return ['error' => 'Promotion date window is invalid'];
        if (($starts !== '' && strtotime($starts) > $now) || ($ends !== '' && strtotime($ends) < $now)) return ['error' => 'Promotion is outside its date window'];
        $campaignState = strtolower(Arr::str($rules, 'campaign_state', 'active'));
        if ($campaignState !== 'active') return ['error' => 'Promotion campaign is not active'];
        $ruleCurrency = strtoupper(Arr::str($rules, 'currency'));
        if ($ruleCurrency !== '' && strtoupper($currency) !== $ruleCurrency) return ['error' => 'Promotion currency mismatch'];
        $requiredGroup = Arr::str($rules, 'customer_group_id');
        if ($requiredGroup !== '' && Arr::str($context, 'customer_group_id') !== $requiredGroup) return ['error' => 'Promotion is not authorized for this customer group'];
        $segments = $this->strings($context['customer_segment_ids'] ?? []);
        if ($segments === [] && Arr::str($context, 'customer_id') !== '' && method_exists($this->reader, 'segmentIds')) $segments = $this->strings(call_user_func([$this->reader, 'segmentIds'], Arr::str($context, 'customer_id')));
        $requiredSegments = $this->strings($rules['customer_segment_ids'] ?? $rules['segment_ids'] ?? []);
        if ($requiredSegments !== [] && array_intersect($requiredSegments, $segments) === []) return ['error' => 'Promotion is not eligible for this customer segment'];
        $scope = strtolower(Arr::str($rules, 'scope', 'cart'));
        $eligible = $subtotalMinor;
        if ($scope === 'item') {
            $eligible = 0;
            $products = $this->strings($rules['product_ids'] ?? []);
            $variants = $this->strings($rules['variant_ids'] ?? []);
            $categories = $this->strings($rules['category_ids'] ?? []);
            if ($products === [] && $variants === [] && $categories === []) return ['error' => 'Item promotion scope is invalid'];
            foreach ($lines as $line) {
                $qty = Arr::int($line, 'qty', Arr::int($line, 'quantity', -1));
                $unitPrice = Arr::int($line, 'unit_price_minor', -1);
                if ($unitPrice < 0 && $qty > 0) $unitPrice = intdiv(Arr::int($line, 'amount_minor', -1), $qty);
                if ($qty <= 0 || $unitPrice < 0) return ['error' => 'Promotion line is invalid'];
                $match = ($variants !== [] && in_array(Arr::str($line, 'variant_id'), $variants, true)) || ($products !== [] && in_array(Arr::str($line, 'product_id'), $products, true)) || ($categories !== [] && in_array(Arr::str($line, 'category_id'), $categories, true));
                if ($match) $eligible += $qty * $unitPrice;
            }
        } elseif ($scope !== 'cart') return ['error' => 'Promotion scope is unsupported'];
        $min = Arr::int($rules, 'min_spend_minor', Arr::int($rules, 'min_subtotal_minor'));
        if ($min < 0) return ['error' => 'Promotion minimum spend is invalid'];
        if ($eligible < $min) return ['error' => "Minimum spend {$min} minor required"];
        $type = Arr::str($rules, 'type', 'fixed');
        if (!in_array($type, ['fixed', 'percent', 'percentage', 'buy_x_get_y'], true)) return ['error' => 'Promotion type is unsupported'];
        if ($type === 'buy_x_get_y') {
            $buyVariants = $this->strings($rules['buy_variant_ids'] ?? []);
            $buyProducts = $this->strings($rules['buy_product_ids'] ?? []);
            $buyCategories = $this->strings($rules['buy_category_ids'] ?? []);
            if ($buyVariants === [] && $buyProducts === [] && $buyCategories === []) return ['error' => 'Combo promotion needs buy targets'];
            $getVariants = $this->strings($rules['get_variant_ids'] ?? []);
            if ($getVariants === []) return ['error' => 'Combo promotion needs gift variants'];
            $buyQty = Arr::int($rules, 'buy_qty', 1);
            $getQty = Arr::int($rules, 'get_qty', 1);
            if ($buyQty < 1 || $getQty < 1) return ['error' => 'Combo quantities must be positive'];
            $maxSets = Arr::int($rules, 'max_sets', 0);
            if ($maxSets < 0) return ['error' => 'Combo max_sets is invalid'];
            $buyMatched = 0;
            $getUnits = [];
            foreach ($lines as $line) {
                $qty = Arr::int($line, 'qty', Arr::int($line, 'quantity', -1));
                $unitPrice = Arr::int($line, 'unit_price_minor', -1);
                if ($unitPrice < 0 && $qty > 0) $unitPrice = intdiv(Arr::int($line, 'amount_minor', -1), $qty);
                if ($qty <= 0 || $unitPrice < 0) return ['error' => 'Promotion line is invalid'];
                $isBuy = ($buyVariants !== [] && in_array(Arr::str($line, 'variant_id'), $buyVariants, true)) || ($buyProducts !== [] && in_array(Arr::str($line, 'product_id'), $buyProducts, true)) || ($buyCategories !== [] && in_array(Arr::str($line, 'category_id'), $buyCategories, true));
                if ($isBuy) $buyMatched += $qty;
                if (in_array(Arr::str($line, 'variant_id'), $getVariants, true)) {
                    for ($i = 0; $i < $qty; $i++) $getUnits[] = $unitPrice;
                }
            }
            $sets = intdiv($buyMatched, $buyQty);
            if ($maxSets > 0) $sets = min($sets, $maxSets);
            if ($sets < 1) return ['error' => 'Combo needs more qualifying items'];
            $freeQty = $sets * $getQty;
            if ($getUnits === []) return ['error' => 'Add the gift item to your cart'];
            sort($getUnits);
            $discount = array_sum(array_slice($getUnits, 0, min($freeQty, count($getUnits))));
            $eligible = $subtotalMinor;
        } else {
            $value = Arr::int($rules, 'value', -1);
            if ($value < 0 || (in_array($type, ['percent', 'percentage'], true) && $value > 100)) return ['error' => 'Promotion value is invalid'];
            $discount = in_array($type, ['percent', 'percentage'], true) ? intdiv($eligible * $value, 100) : $value;
        }
        $max = $rules['max_discount_minor'] ?? null;
        if (is_numeric($max) && (int) $max < 0) return ['error' => 'Promotion maximum discount is invalid'];
        if (is_numeric($max) && $discount > (int) $max) $discount = (int) $max;
        $limit = Arr::int($rules, 'usage_limit', Arr::int($rules, 'max_uses'));
        $used = 0;
        if (method_exists($this->reader, 'usageCount')) { $count = call_user_func([$this->reader, 'usageCount'], Arr::requiredStr($coupon, 'id')); $used = is_numeric($count) ? (int) $count : 0; }
        if ($limit > 0 && $used >= $limit) return ['error' => 'Promotion usage limit reached'];
        $perCustomerLimit = Arr::int($rules, 'per_customer_limit', Arr::int($rules, 'per_user_limit'));
        if ($perCustomerLimit > 0 && Arr::str($context, 'customer_id') !== '') {
            $customerUsed = 0;
            if (method_exists($this->reader, 'usageCountForCustomer')) {
                $raw = call_user_func([$this->reader, 'usageCountForCustomer'], Arr::requiredStr($coupon, 'id'), Arr::str($context, 'customer_id'));
                $customerUsed = is_numeric($raw) ? (int) $raw : 0;
            }
            if ($customerUsed >= $perCustomerLimit) return ['error' => 'Promotion per-customer limit reached'];
        }
        $window = Arr::int($rules, 'rate_limit_window_seconds');
        $attemptLimit = Arr::int($rules, 'rate_limit_attempts', Arr::int($rules, 'max_attempts'));
        if ($window < 0 || $attemptLimit < 0) return ['error' => 'Promotion rate limit is invalid'];
        if ($window > 0 && $attemptLimit > 0 && method_exists($this->reader, 'attemptCount')) {
            $key = hash('sha256', $this->actorKey($context));
            $rawAttempts = call_user_func([$this->reader, 'attemptCount'], Arr::requiredStr($coupon, 'id'), $key, $window);
            $attempts = is_numeric($rawAttempts) ? (int) $rawAttempts : 0;
            if ($attempts >= $attemptLimit) return ['error' => 'Promotion rate limit reached'];
        }
        $applied = $context['applied_promotions'] ?? [];
        if (!is_array($applied)) $applied = [];
        $exclusive = ($rules['exclusive'] ?? false) === true || Arr::int($rules, 'exclusive') === 1;
        $previousExclusive = false;
        foreach ($applied as $previous) {
            if (is_array($previous)) {
                $previousRow = [];
                foreach ($previous as $key => $value) if (is_string($key)) $previousRow[$key] = $value;
                if (($previousRow['exclusive'] ?? false) === true || Arr::int($previousRow, 'exclusive') === 1) $previousExclusive = true;
            }
        }
        if ($applied !== [] && ($exclusive || $previousExclusive)) return ['error' => 'Promotion cannot be stacked'];
        $result = ['discount_minor' => max(0, min($discount, $eligible, $subtotalMinor)), 'coupon_id' => Arr::requiredStr($coupon, 'id')];
        if ($currency !== 'VND' || $lines !== [] || $context !== []) $result += ['code' => $code, 'type' => $type, 'scope' => $scope, 'currency' => $currency, 'eligible_minor' => $eligible, 'exclusive' => $exclusive];
        return $result;
    }
    /**
     * @param array<string, mixed> $context
     * @param array<string, mixed> $result
     */
    private function audit(string $code, int $subtotal, string $currency, array $context, array $result): void
    {
        if ($this->audit === null) return;
        $safeContext = [];
        foreach (['customer_id', 'customer_group_id', 'request_id', 'cart_id', 'channel_id'] as $key) if (isset($context[$key]) && is_scalar($context[$key])) $safeContext[$key] = (string) $context[$key];
        try { $this->audit->record(['coupon_id' => $result['coupon_id'] ?? null, 'code_hash' => hash('sha256', $code), 'decision' => isset($result['error']) ? 'rejected' : 'accepted', 'reason' => isset($result['error']) && is_scalar($result['error']) ? (string) $result['error'] : '', 'currency' => strtoupper($currency), 'subtotal_minor' => max(0, $subtotal), 'discount_minor' => max(0, Arr::int($result, 'discount_minor')), 'actor_hash' => hash('sha256', $this->actorKey($context)), 'context' => $safeContext]); } catch (\Throwable) { /* audit failure must not change checkout authority */ }
    }
    /** @param array<string, mixed> $context */
    private function actorKey(array $context): string { return implode('|', [Arr::str($context, 'customer_id'), Arr::str($context, 'ip'), Arr::str($context, 'device_id'), Arr::str($context, 'session_id')]); }
    public function recordUsage(string $couponId, string $orderId, int $discountMinor): void { $this->usage->record($couponId, $orderId, $discountMinor); }
    public function reserveUsage(string $couponId, string $reservationKey, int $discountMinor): void { if (!method_exists($this->usage, 'reserve')) throw new \RuntimeException('Promotion usage reservation unavailable'); $this->usage->reserve($couponId, $reservationKey, $discountMinor); }
    public function commitUsage(string $reservationKey): void { if (method_exists($this->usage, 'commit')) $this->usage->commit($reservationKey); }
    public function rollbackUsage(string $reservationKey): void { if (method_exists($this->usage, 'rollback')) $this->usage->rollback($reservationKey); }
    /** @return array<int, string> */
    private function strings(mixed $value): array { $out = []; if (is_array($value)) foreach ($value as $item) if (is_string($item) && $item !== '') $out[] = $item; return $out; }
}
