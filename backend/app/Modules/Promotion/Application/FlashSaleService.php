<?php
declare(strict_types=1);
namespace App\Modules\Promotion\Application;

use App\Models\FlashSale;
use App\Models\FlashSaleItem;
use App\Support\Arr;
use App\Support\Ids;
use Siro\Core\Database;

/**
 * Flash-sale engine (Hasaki-style giờ vàng): time-boxed variant prices with
 * quantity caps. Active = state active AND now within [starts_at, ends_at].
 * Cap accounting is serialized with row locks inside the caller's transaction.
 */
final class FlashSaleService
{
    /**
     * Best active deal per variant (lowest price, then highest priority).
     * Tier-gated sales (min_tier) only surface for eligible viewer tiers.
     * @param array<int, string> $variantIds
     * @return array<string, array<string, mixed>> keyed by variant_id
     */
    public function activeFor(array $variantIds, ?int $now = null, ?string $viewerTier = null): array
    {
        $now ??= time();
        $out = [];
        if ($variantIds === []) return $out;
        $sales = [];
        foreach (FlashSale::query()->where('state', 'active')->get() as $s) {
            $row = Arr::row($s);
            $start = strtotime(Arr::str($row, 'starts_at'));
            $end = strtotime(Arr::str($row, 'ends_at'));
            if ($start === false || $end === false || $start > $now || $end < $now) continue;
            $minTier = MemberPriceService::normalizeTier(Arr::str($row, 'min_tier'));
            if ($minTier !== null && !MemberPriceService::tierCanSee($viewerTier, $minTier)) continue;
            $sales[Arr::str($row, 'id')] = $row;
        }
        if ($sales === []) return $out;
        foreach (FlashSaleItem::query()->whereIn('sale_id', array_keys($sales))->whereIn('variant_id', array_values($variantIds))->get() as $item) {
            $ir = Arr::row($item);
            $vid = Arr::str($ir, 'variant_id');
            $sale = $sales[Arr::str($ir, 'sale_id')] ?? null;
            if ($sale === null) continue;
            $cap = Arr::int($ir, 'cap_qty');
            $sold = Arr::int($ir, 'sold_qty');
            if ($cap > 0 && $sold >= $cap) continue;
            $candidate = [
                'sale_id' => Arr::str($sale, 'id'),
                'sale_name' => Arr::str($sale, 'name'),
                'variant_id' => $vid,
                'min_tier' => MemberPriceService::normalizeTier(Arr::str($sale, 'min_tier')),
                'sale_price_minor' => Arr::int($ir, 'sale_price_minor'),
                'starts_at' => Arr::str($sale, 'starts_at'),
                'ends_at' => Arr::str($sale, 'ends_at'),
                'cap_qty' => $cap,
                'sold_qty' => $sold,
                'remaining' => $cap > 0 ? max(0, $cap - $sold) : null,
                'priority' => Arr::int($sale, 'priority'),
            ];
            $prev = $out[$vid] ?? null;
            if ($prev === null || $candidate['sale_price_minor'] < $prev['sale_price_minor'] || ($candidate['sale_price_minor'] === $prev['sale_price_minor'] && $candidate['priority'] > $prev['priority'])) {
                $out[$vid] = $candidate;
            }
        }
        return $out;
    }

    /** Reserve cap units inside the caller's transaction. Throws when sold out. */
    public function recordSale(string $variantId, int $qty, ?int $now = null): array
    {
        $now ??= time();
        $itemQuery = FlashSaleItem::query()->where('variant_id', $variantId);
        if (method_exists($itemQuery, 'lockForUpdate')) $itemQuery = $itemQuery->lockForUpdate();
        $best = null;
        $bestSale = null;
        foreach ($itemQuery->get() as $item) {
            $ir = Arr::row($item);
            $sale = FlashSale::query()->where('id', Arr::str($ir, 'sale_id'))->first();
            if ($sale === null) continue;
            $sr = Arr::row($sale);
            if (Arr::str($sr, 'state') !== 'active') continue;
            $start = strtotime(Arr::str($sr, 'starts_at'));
            $end = strtotime(Arr::str($sr, 'ends_at'));
            if ($start === false || $end === false || $start > $now || $end < $now) continue;
            $cap = Arr::int($ir, 'cap_qty');
            $sold = Arr::int($ir, 'sold_qty');
            if ($cap > 0 && $sold + $qty > $cap) continue;
            if ($best === null || Arr::int($ir, 'sale_price_minor') < Arr::int(Arr::row($best), 'sale_price_minor')) {
                $best = $item;
                $bestSale = $sr;
            }
        }
        if ($best === null) throw new \RuntimeException('Flash sale is not available for this variant');
        $row = Arr::row($best);
        $newSold = Arr::int($row, 'sold_qty') + $qty;
        $best->setAttribute('sold_qty', $newSold);
        $best->save();
        return [
            'sale_id' => Arr::str($bestSale, 'id'),
            'sale_name' => Arr::str($bestSale, 'name'),
            'variant_id' => $variantId,
            'sale_price_minor' => Arr::int($row, 'sale_price_minor'),
            'ends_at' => Arr::str($bestSale, 'ends_at'),
        ];
    }

    public function releaseSale(string $variantId, string $saleId, int $qty): void
    {
        try {
            $item = FlashSaleItem::query()->where('sale_id', $saleId)->where('variant_id', $variantId)->first();
            if ($item === null) return;
            $row = Arr::row($item);
            $item->setAttribute('sold_qty', max(0, Arr::int($row, 'sold_qty') - $qty));
            $item->save();
        } catch (\Throwable $e) {
        }
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    public function createSale(array $data): array
    {
        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 120) throw new \InvalidArgumentException('Sale name is required (max 120)');
        $starts = (string) ($data['starts_at'] ?? '');
        $ends = (string) ($data['ends_at'] ?? '');
        if (strtotime($starts) === false || strtotime($ends) === false || strtotime($starts) >= strtotime($ends)) throw new \InvalidArgumentException('Invalid sale window');
        $state = strtolower((string) ($data['state'] ?? 'draft'));
        if (!in_array($state, ['draft', 'active', 'archived'], true)) throw new \InvalidArgumentException('Invalid sale state');
        $now = date('Y-m-d H:i:s');
        $payload = [
            'id' => Ids::new(),
            'name' => $name,
            'state' => $state,
            'starts_at' => date('Y-m-d H:i:s', strtotime($starts)),
            'ends_at' => date('Y-m-d H:i:s', strtotime($ends)),
            'priority' => max(0, (int) ($data['priority'] ?? 0)),
            'created_at' => $now,
            'updated_at' => $now,
        ];
        Database::table('flash_sales')->insert($payload);
        $row = FlashSale::query()->where('id', $payload['id'])->first();
        return $row ? Arr::row($row) : $payload;
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    public function addItem(string $saleId, array $data): array
    {
        $variantId = trim((string) ($data['variant_id'] ?? ''));
        if ($variantId === '') throw new \InvalidArgumentException('variant_id is required');
        if (\App\Models\ProductVariant::query()->where('id', $variantId)->first() === null) throw new \InvalidArgumentException('Variant not found');
        $price = $data['sale_price_minor'] ?? null;
        if (!is_int($price) && !ctype_digit((string) $price)) throw new \InvalidArgumentException('sale_price_minor must be an integer');
        $price = (int) $price;
        if ($price < 0) throw new \InvalidArgumentException('sale_price_minor must be >= 0');
        $cap = (int) ($data['cap_qty'] ?? 0);
        if ($cap < 0 || $cap > 100000) throw new \InvalidArgumentException('cap_qty must be 0-100000 (0 = unlimited)');
        $exists = FlashSaleItem::query()->where('sale_id', $saleId)->where('variant_id', $variantId)->first();
        if ($exists !== null) throw new \RuntimeException('Variant already in this sale');
        $payload = ['id' => Ids::new(), 'sale_id' => $saleId, 'variant_id' => $variantId, 'sale_price_minor' => $price, 'cap_qty' => $cap, 'sold_qty' => 0, 'created_at' => date('Y-m-d H:i:s')];
        Database::table('flash_sale_items')->insert($payload);
        $row = FlashSaleItem::query()->where('id', $payload['id'])->first();
        return $row ? Arr::row($row) : $payload;
    }
}
