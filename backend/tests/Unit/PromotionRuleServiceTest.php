<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\PromotionRule;
use App\Modules\Promotion\Application\PromotionRuleService;
use Tests\TestCase;

final class PromotionRuleServiceTest extends TestCase
{
    private PromotionRuleService $rules;

    protected function setUp(): void
    {
        parent::setUp();
        $this->rules = new PromotionRuleService();
    }

    /** @param array<int, array<string, mixed>> $lines @return array{rule: array<string, mixed>, discount: int, evidence: array<string, mixed>}|null */
    private function best(array $lines, int $subtotal, int $now): ?array
    {
        return $this->rules->bestFor($lines, $subtotal, $now);
    }

    public function testLargestDiscountWinsAndRulesNeverStack(): void
    {
        $now = time();
        $this->rules->create(['name' => 'Ten percent', 'kind' => 'cart_percent', 'value' => 10]);
        $fixed = $this->rules->create(['name' => 'Fixed 30000', 'kind' => 'cart_fixed', 'value' => 30000]);

        $best = $this->best([], 100000, $now);
        self::assertNotNull($best);
        self::assertSame($fixed['id'], $best['rule']['id']);
        self::assertSame(30000, $best['discount']);
        self::assertSame('cart_fixed', $best['rule']['kind']);
    }

    public function testCartPercentFloorsAndCapsAtSubtotal(): void
    {
        $now = time();
        $this->rules->create(['name' => 'Ninety', 'kind' => 'cart_percent', 'value' => 90]);
        $best = $this->best([], 999, $now);
        self::assertNotNull($best);
        self::assertSame(899, $best['discount']);

        PromotionRule::query()->delete();
        $this->rules->create(['name' => 'Fixed huge', 'kind' => 'cart_fixed', 'value' => 1000000]);
        $capped = $this->best([], 1234, $now);
        self::assertNotNull($capped);
        self::assertSame(1234, $capped['discount']);
    }

    public function testCatalogPercentOnlyCountsMatchingCategoryLines(): void
    {
        $now = time();
        $this->rules->create(['name' => 'Skincare 20', 'kind' => 'catalog_percent', 'value' => 20, 'category_slugs' => ['skincare']]);
        $lines = [
            ['variant_id' => 'v-skin', 'qty' => 2, 'unit_price_minor' => 25000, 'category_slug' => 'skincare'],
            ['variant_id' => 'v-shoes', 'qty' => 1, 'unit_price_minor' => 40000, 'category_slug' => 'shoes'],
        ];
        $best = $this->best($lines, 90000, $now);
        self::assertNotNull($best);
        self::assertSame(10000, $best['discount']);
        self::assertCount(1, $best['evidence']['lines']);
        self::assertSame('v-skin', $best['evidence']['lines'][0]['variant_id']);
        self::assertSame(50000, $best['evidence']['base_minor']);
    }

    public function testWindowBoundariesAreInclusive(): void
    {
        $now = time();
        $this->rules->create([
            'name' => 'Window',
            'kind' => 'cart_fixed',
            'value' => 5000,
            'starts_at' => date('Y-m-d H:i:s', $now),
            'ends_at' => date('Y-m-d H:i:s', $now),
        ]);
        self::assertNotNull($this->best([], 20000, $now));
        self::assertNull($this->best([], 20000, $now - 1));
        self::assertNull($this->best([], 20000, $now + 1));
    }

    public function testMaxUsesAndDisabledStateAreIgnoredAtReadTime(): void
    {
        $now = time();
        $exhausted = $this->rules->create(['name' => 'Exhausted', 'kind' => 'cart_fixed', 'value' => 5000, 'max_uses' => 1]);
        PromotionRule::query()->where('id', $exhausted['id'])->first()?->fill(['uses' => 1])->save();
        self::assertNull($this->best([], 20000, $now));

        $available = $this->rules->create(['name' => 'Available', 'kind' => 'cart_fixed', 'value' => 3000, 'max_uses' => 2]);
        PromotionRule::query()->where('id', $available['id'])->first()?->fill(['uses' => 1])->save();
        self::assertNotNull($this->best([], 20000, $now));

        $disabled = $this->rules->create(['name' => 'Disabled', 'kind' => 'cart_fixed', 'value' => 9000, 'state' => 'disabled']);
        self::assertSame($available['id'], $this->best([], 20000, $now)['rule']['id'] ?? null);
        self::assertNotSame($disabled['id'], $this->best([], 20000, $now)['rule']['id'] ?? null);
    }

    public function testMinimumSubtotalFiltersRule(): void
    {
        $now = time();
        $this->rules->create(['name' => 'Min', 'kind' => 'cart_fixed', 'value' => 5000, 'min_subtotal_minor' => 50000]);
        self::assertNull($this->best([], 49999, $now));
        self::assertNotNull($this->best([], 50000, $now));
    }
}
