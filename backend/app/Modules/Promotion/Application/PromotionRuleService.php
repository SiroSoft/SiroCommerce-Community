<?php

declare(strict_types=1);

namespace App\Modules\Promotion\Application;

use App\Models\Category;
use App\Models\Product;
use App\Models\PromotionRule;
use App\Support\Arr;
use App\Support\Ids;
use Siro\Core\Database;

/**
 * Free-tier price rules (spec PROMOTION_RULES_SPEC).
 *
 * Rules never stack: the single largest discount wins. Shipping and tax are
 * outside this engine; callers apply the returned discount to the already
 * discounted merchandise subtotal and carry the evidence into their response.
 */
final class PromotionRuleService
{
    public const KINDS = ['cart_percent', 'cart_fixed', 'catalog_percent'];

    /** @var array<string, string> */
    private array $categorySlugCache = [];

    /** @var array<string, string> */
    private array $productCategoryCache = [];

    /**
     * Active rules whose window contains $now and which still have uses left.
     *
     * @return array<int, array<string, mixed>>
     */
    public function activeRules(?int $now = null): array
    {
        $now ??= time();
        $rules = [];
        foreach (PromotionRule::query()->where('state', 'active')->orderBy('id', 'asc')->get() as $model) {
            $rule = $this->normalizeRule(Arr::row($model));
            if (!$this->isWithinWindow($rule, $now)) continue;
            if (!$this->hasUsesRemaining($rule)) continue;
            $rules[] = $rule;
        }
        return $rules;
    }

    /**
     * Best single rule for the provided lines and already-discounted subtotal.
     *
     * @param array<int, array<string, mixed>> $lines
     * @return array{rule: array<string, mixed>, discount: int, evidence: array<string, mixed>}|null
     */
    public function bestFor(array $lines, int $subtotalMinor, ?int $now = null): ?array
    {
        if ($subtotalMinor <= 0) return null;
        $best = null;
        foreach ($this->activeRules($now) as $rule) {
            $minimum = $rule['min_subtotal_minor'];
            if (is_int($minimum) && $minimum > 0 && $subtotalMinor < $minimum) continue;
            [$discount, $evidence] = $this->calculate($rule, $lines, $subtotalMinor);
            if ($discount <= 0) continue;
            if ($best === null || $discount > $best['discount']) {
                $best = ['rule' => $rule, 'discount' => $discount, 'evidence' => $evidence];
            }
        }
        return $best;
    }

    /**
     * Increment one use inside the caller's order transaction. Fails closed if
     * the rule was disabled or exhausted between quote and place.
     */
    public function incrementUsage(string $id, ?int $now = null): void
    {
        $now ??= time();
        Database::transaction(function () use ($id, $now): void {
            $row = PromotionRule::query()->where('id', $id)->lockForUpdate()->first();
            if ($row === null) throw new \RuntimeException('Promotion rule no longer exists');
            $rule = $this->normalizeRule(Arr::row($row));
            if ($rule['state'] !== 'active') throw new \RuntimeException('Promotion rule is not active');
            if (!$this->isWithinWindow($rule, $now)) throw new \RuntimeException('Promotion rule is outside its date window');
            if (!$this->hasUsesRemaining($rule)) throw new \RuntimeException('Promotion rule usage limit reached');
            PromotionRule::query()->where('id', $id)->update([
                'uses' => Arr::int($rule, 'uses') + 1,
                'updated_at' => date('Y-m-d H:i:s', $now),
            ]);
        });
    }

    /** @return array<int, array<string, mixed>> */
    public function all(): array
    {
        $out = [];
        foreach (PromotionRule::query()->orderBy('created_at', 'desc')->orderBy('id', 'desc')->get() as $model) {
            $out[] = $this->normalizeRule(Arr::row($model));
        }
        return $out;
    }

    /** @return array<string, mixed>|null */
    public function find(string $id): ?array
    {
        $model = PromotionRule::query()->where('id', $id)->first();
        return $model === null ? null : $this->normalizeRule(Arr::row($model));
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function create(array $data): array
    {
        $now = date('Y-m-d H:i:s');
        $row = $this->storageRow($data);
        $row['id'] = Arr::str($data, 'id', Ids::new());
        $row['state'] = Arr::str($row, 'state', 'active');
        $row['uses'] = 0;
        $row['created_at'] = $now;
        $row['updated_at'] = $now;
        $model = PromotionRule::create($row);
        return $this->normalizeRule(Arr::row($model));
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>|null
     */
    public function update(string $id, array $data): ?array
    {
        $model = PromotionRule::query()->where('id', $id)->first();
        if ($model === null) return null;
        $patch = $this->storageRow($data);
        if ($patch !== []) {
            $patch['updated_at'] = date('Y-m-d H:i:s');
            $model->fill($patch);
            $model->save();
        }
        return $this->normalizeRule(Arr::row($model));
    }

    public function delete(string $id): bool
    {
        $model = PromotionRule::query()->where('id', $id)->first();
        if ($model === null) return false;
        $model->delete();
        return true;
    }

    /**
     * @param array<string, mixed> $rule
     * @param array<int, array<string, mixed>> $lines
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function calculate(array $rule, array $lines, int $subtotalMinor): array
    {
        $kind = Arr::str($rule, 'kind');
        $value = max(0, Arr::int($rule, 'value'));
        if ($kind === 'cart_percent') {
            $discount = intdiv($subtotalMinor * $value, 100);
            return [min($discount, $subtotalMinor), ['scope' => 'cart', 'base_minor' => $subtotalMinor, 'percent' => $value]];
        }
        if ($kind === 'cart_fixed') {
            return [min($value, $subtotalMinor), ['scope' => 'cart', 'base_minor' => $subtotalMinor, 'amount_minor' => $value]];
        }
        if ($kind === 'catalog_percent') {
            $categories = $this->stringList($rule['category_slugs'] ?? []);
            $eligibleMinor = 0;
            $lineEvidence = [];
            foreach (array_values($lines) as $index => $line) {
                $lineTotal = $this->lineTotal($line);
                if ($lineTotal <= 0) continue;
                $lineCategories = $this->lineCategories($line);
                if ($categories !== [] && array_intersect($categories, $lineCategories) === []) continue;
                $eligibleMinor += $lineTotal;
                $lineEvidence[] = [
                    'index' => $index,
                    'variant_id' => Arr::str($line, 'variant_id'),
                    'category_slugs' => $lineCategories,
                    'line_total_minor' => $lineTotal,
                    'discount_minor' => intdiv($lineTotal * $value, 100),
                ];
            }
            $discount = min(intdiv($eligibleMinor * $value, 100), $subtotalMinor);
            return [$discount, ['scope' => 'catalog', 'base_minor' => $eligibleMinor, 'percent' => $value, 'lines' => $lineEvidence]];
        }
        return [0, ['scope' => 'unsupported']];
    }

    /** @param array<string, mixed> $rule */
    private function isWithinWindow(array $rule, int $now): bool
    {
        $starts = Arr::nullableStr($rule, 'starts_at');
        $ends = Arr::nullableStr($rule, 'ends_at');
        if ($starts !== null && $starts !== '') {
            $startsAt = strtotime($starts);
            if ($startsAt === false || $startsAt > $now) return false;
        }
        if ($ends !== null && $ends !== '') {
            $endsAt = strtotime($ends);
            if ($endsAt === false || $endsAt < $now) return false;
        }
        return true;
    }

    /** @param array<string, mixed> $rule */
    private function hasUsesRemaining(array $rule): bool
    {
        $maxUses = $rule['max_uses'] ?? null;
        if (!is_int($maxUses)) return true;
        return Arr::int($rule, 'uses') < $maxUses;
    }

    /** @param array<string, mixed> $line */
    private function lineTotal(array $line): int
    {
        $lineTotal = Arr::int($line, 'line_total_minor', -1);
        if ($lineTotal >= 0) return $lineTotal;
        $qty = Arr::int($line, 'qty', -1);
        $unit = Arr::int($line, 'unit_price_minor', -1);
        if ($qty <= 0 || $unit < 0) return 0;
        return $qty * $unit;
    }

    /**
     * @param array<string, mixed> $line
     * @return array<int, string>
     */
    private function lineCategories(array $line): array
    {
        $explicit = $this->stringList($line['category_slugs'] ?? []);
        $single = Arr::str($line, 'category_slug');
        if ($single !== '') $explicit[] = $single;
        if ($explicit !== []) return array_values(array_unique(array_map('strtolower', $explicit)));

        $categoryId = Arr::str($line, 'category_id');
        if ($categoryId !== '') {
            $slug = $this->categorySlug($categoryId);
            if ($slug !== '') return [$slug];
        }

        $productId = Arr::str($line, 'product_id');
        if ($productId !== '') {
            $categoryId = $this->productCategoryId($productId);
            if ($categoryId !== '') {
                $slug = $this->categorySlug($categoryId);
                if ($slug !== '') return [$slug];
            }
        }
        return [];
    }

    private function categorySlug(string $categoryId): string
    {
        if (array_key_exists($categoryId, $this->categorySlugCache)) return $this->categorySlugCache[$categoryId];
        $model = Category::query()->where('id', $categoryId)->first();
        $slug = $model === null ? '' : strtolower(Arr::str(Arr::row($model), 'slug'));
        $this->categorySlugCache[$categoryId] = $slug;
        return $slug;
    }

    private function productCategoryId(string $productId): string
    {
        if (array_key_exists($productId, $this->productCategoryCache)) return $this->productCategoryCache[$productId];
        $model = Product::query()->where('id', $productId)->first();
        $categoryId = $model === null ? '' : Arr::str(Arr::row($model), 'category_id');
        $this->productCategoryCache[$productId] = $categoryId;
        return $categoryId;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function normalizeRule(array $row): array
    {
        $row['value'] = Arr::int($row, 'value');
        $row['uses'] = Arr::int($row, 'uses');
        $row['max_uses'] = isset($row['max_uses']) && is_numeric($row['max_uses']) ? (int) $row['max_uses'] : null;
        $row['min_subtotal_minor'] = isset($row['min_subtotal_minor']) && is_numeric($row['min_subtotal_minor']) ? (int) $row['min_subtotal_minor'] : null;
        $row['category_slugs'] = $this->decodeSlugs(Arr::str($row, 'category_slugs_json', ''));
        $row['discount_minor'] = 0;
        return $row;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function storageRow(array $data): array
    {
        $out = [];
        foreach (['name', 'kind', 'state'] as $key) {
            if (array_key_exists($key, $data) && is_string($data[$key])) $out[$key] = trim($data[$key]);
        }
        if (array_key_exists('value', $data)) {
            $value = $data['value'];
            $out['value'] = is_numeric($value) ? max(0, (int) $value) : 0;
        }
        if (array_key_exists('min_subtotal_minor', $data)) {
            $value = $data['min_subtotal_minor'];
            $out['min_subtotal_minor'] = $value === null || $value === '' || !is_numeric($value) ? null : max(0, (int) $value);
        }
        if (array_key_exists('max_uses', $data)) {
            $value = $data['max_uses'];
            $out['max_uses'] = $value === null || $value === '' || !is_numeric($value) ? null : max(0, (int) $value);
        }
        if (array_key_exists('category_slugs', $data)) {
            $out['category_slugs_json'] = json_encode($this->stringList($data['category_slugs']), JSON_THROW_ON_ERROR);
        } elseif (array_key_exists('category_slugs_json', $data)) {
            $raw = $data['category_slugs_json'];
            if ($raw === null || $raw === '') {
                $out['category_slugs_json'] = null;
            } else {
                $decoded = is_string($raw) ? json_decode($raw, true) : $raw;
                $out['category_slugs_json'] = json_encode($this->stringList(is_array($decoded) ? $decoded : []), JSON_THROW_ON_ERROR);
            }
        }
        foreach (['starts_at', 'ends_at'] as $key) {
            if (!array_key_exists($key, $data)) continue;
            $raw = $data[$key];
            if ($raw === null || $raw === '') {
                $out[$key] = null;
                continue;
            }
            $ts = is_string($raw) ? strtotime($raw) : false;
            $out[$key] = $ts === false ? null : date('Y-m-d H:i:s', $ts);
        }
        return $out;
    }

    /** @return array<int, string> */
    private function decodeSlugs(string $raw): array
    {
        if ($raw === '') return [];
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $this->stringList($decoded) : [];
    }

    /** @return array<int, string> */
    private function stringList(mixed $value): array
    {
        if (is_string($value)) $value = [$value];
        if (!is_array($value)) return [];
        $out = [];
        foreach ($value as $item) {
            if (is_string($item) && trim($item) !== '') $out[] = strtolower(trim($item));
        }
        return array_values(array_unique($out));
    }
}
