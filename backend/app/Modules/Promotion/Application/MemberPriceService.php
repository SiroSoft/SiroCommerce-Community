<?php
declare(strict_types=1);
namespace App\Modules\Promotion\Application;

use App\Models\MemberPrice;
use App\Support\Arr;
use App\Support\Ids;
use Siro\Core\Database;

/**
 * Native B2C member pricing (Hasaki-style giá member): tier prices per variant.
 * Tier truth is the latest customer_loyalty_snapshot row; ERP Lite feeds it.
 */
final class MemberPriceService
{
    public const TIERS = ['bronze', 'silver', 'gold', 'platinum'];

    public static function normalizeTier(string $tier): ?string
    {
        $t = strtolower(trim($tier));
        return in_array($t, self::TIERS, true) ? $t : null;
    }

    /** Rank for gating: bronze 0 .. platinum 3, unknown/null -1. */
    public static function rankOf(?string $tier): int
    {
        $t = $tier !== null ? self::normalizeTier($tier) : null;
        if ($t === null) return -1;
        $rank = array_search($t, self::TIERS, true);
        return $rank === false ? -1 : (int) $rank;
    }

    /**
     * Product visibility floor per variant (null = public).
     * @param list<string> $variantIds @return array<string, string|null> variant_id => min_tier
     */
    public static function productTiers(array $variantIds): array
    {
        $out = [];
        $ids = array_values(array_unique(array_filter($variantIds)));
        if ($ids === []) return $out;
        try {
            $products = [];
            foreach (\App\Models\ProductVariant::query()->whereIn('id', $ids)->get() as $m) {
                $row = Arr::row($m);
                $vid = Arr::str($row, 'id');
                $pid = Arr::str($row, 'product_id');
                if ($vid !== '' && $pid !== '') $products[$vid] = $pid;
            }
            $minTiers = [];
            foreach (\App\Models\Product::query()->whereIn('id', array_values(array_unique(array_values($products))))->get() as $m) {
                $row = Arr::row($m);
                $pid = Arr::str($row, 'id');
                if ($pid !== '') $minTiers[$pid] = self::normalizeTier(Arr::str($row, 'min_tier'));
            }
            foreach ($products as $vid => $pid) $out[$vid] = $minTiers[$pid] ?? null;
        } catch (\Throwable $e) {
            // Fail-open here would leak gated products; callers treat a
            // missing entry as unknown and fail closed at checkout.
        }
        return $out;
    }

    /** True when the viewer tier may see/buy a min_tier-gated product. */
    public static function tierCanSee(?string $viewerTier, ?string $minTier): bool
    {
        if ($minTier === null) return true;
        return self::rankOf($viewerTier) >= self::rankOf($minTier);
    }

    public function tierFor(string $customerId): ?string
    {
        if ($customerId === '') return null;
        try {
            $rows = Database::table('customer_loyalty_snapshot')->where('customer_id', $customerId)->orderBy('applied_at', 'desc')->limit(1)->get();
            $list = is_array($rows) ? $rows : iterator_to_array($rows);
            $first = $list[0] ?? null;
            if ($first === null) return null;
            return self::normalizeTier(Arr::str(Arr::row($first), 'tier'));
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * @param array<int, array<string, mixed>> $lines (needs variant_id, qty, unit_price_minor)
     * @return array{tier: string|null, discount_minor: int}
     */
    public function discountFor(string $customerId, array $lines, string $currency): array
    {
        $tier = $this->tierFor($customerId);
        if ($tier === null || $lines === []) return ['tier' => null, 'discount_minor' => 0];
        $variantIds = [];
        foreach ($lines as $line) {
            $vid = Arr::str($line, 'variant_id');
            if ($vid !== '') $variantIds[] = $vid;
        }
        $variantIds = array_values(array_unique($variantIds));
        if ($variantIds === []) return ['tier' => null, 'discount_minor' => 0];
        $prices = [];
        try {
            foreach (MemberPrice::query()->whereIn('variant_id', $variantIds)->where('tier', $tier)->get() as $m) {
                $row = Arr::row($m);
                if (strtoupper(Arr::str($row, 'currency', $currency)) !== strtoupper($currency)) continue;
                $prices[Arr::str($row, 'variant_id')] = Arr::int($row, 'price_minor');
            }
        } catch (\Throwable $e) {
            return ['tier' => null, 'discount_minor' => 0];
        }
        $discount = 0;
        foreach ($lines as $line) {
            $vid = Arr::str($line, 'variant_id');
            if (!isset($prices[$vid])) continue;
            $qty = Arr::int($line, 'qty', Arr::int($line, 'quantity', 0));
            $unit = Arr::int($line, 'unit_price_minor', -1);
            if ($qty <= 0 || $unit < 0) continue;
            $memberUnit = $prices[$vid];
            if ($memberUnit < $unit) $discount += ($unit - $memberUnit) * $qty;
        }
        if ($discount <= 0) return ['tier' => null, 'discount_minor' => 0];
        return ['tier' => $tier, 'discount_minor' => $discount];
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    public function setPrice(array $data): array
    {
        $variantId = trim((string) ($data['variant_id'] ?? ''));
        if ($variantId === '') throw new \InvalidArgumentException('variant_id is required');
        if (\App\Models\ProductVariant::query()->where('id', $variantId)->first() === null) throw new \InvalidArgumentException('Variant not found');
        $tier = self::normalizeTier((string) ($data['tier'] ?? ''));
        if ($tier === null) throw new \InvalidArgumentException('tier must be bronze|silver|gold|platinum');
        $price = $data['price_minor'] ?? null;
        if (!is_int($price) && !ctype_digit((string) $price)) throw new \InvalidArgumentException('price_minor must be an integer');
        $price = (int) $price;
        if ($price < 0) throw new \InvalidArgumentException('price_minor must be >= 0');
        $currency = strtoupper(trim((string) ($data['currency'] ?? 'VND')));
        if (!preg_match('/^[A-Z]{3}$/', $currency)) throw new \InvalidArgumentException('Invalid currency');
        $existing = MemberPrice::query()->where('variant_id', $variantId)->where('tier', $tier)->where('currency', $currency)->first();
        $now = date('Y-m-d H:i:s');
        if ($existing !== null) {
            $existing->setAttribute('price_minor', $price);
            $existing->setAttribute('updated_at', $now);
            $existing->save();
            return Arr::row($existing);
        }
        $payload = ['id' => Ids::new(), 'variant_id' => $variantId, 'tier' => $tier, 'price_minor' => $price, 'currency' => $currency, 'created_at' => $now, 'updated_at' => $now];
        Database::table('member_prices')->insert($payload);
        $row = MemberPrice::query()->where('id', $payload['id'])->first();
        return $row ? Arr::row($row) : $payload;
    }
}
