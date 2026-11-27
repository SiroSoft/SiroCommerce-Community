<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Extensions\Siro\ProductLabels\RuleEvaluator;
use Tests\TestCase;

require_once dirname(__DIR__, 2) . '/app/Extensions/Siro/ProductLabels/src/RuleEvaluator.php';

final class ProductLabelRuleEvaluatorTest extends TestCase
{
    /** @return array<string, mixed> */
    private function product(array $overrides = []): array
    {
        return array_merge([
            'id' => 'p-1',
            'slug' => 'linen-shirt',
            'category' => 'shirts',
            'tags' => ['eco', 'summer'],
            'price_minor' => 80000,
            'compare_at_price_minor' => 100000,
            'rating' => 4.6,
            'review_count' => 12,
            'stock' => 3,
            'created_at' => date('Y-m-d H:i:s', time() - 2 * 86400),
            'status' => 'active',
        ], $overrides);
    }

    public function testEmptyConditionsMatchEverything(): void
    {
        self::assertTrue(RuleEvaluator::matches([], $this->product()));
    }

    public function testIsNewWithinDays(): void
    {
        self::assertTrue(RuleEvaluator::matches(['is_new_days' => 30], $this->product()));
        self::assertFalse(RuleEvaluator::matches(['is_new_days' => 1], $this->product()));
        self::assertFalse(RuleEvaluator::matches(
            ['is_new_days' => 30],
            $this->product(['created_at' => date('Y-m-d H:i:s', time() - 60 * 86400)])
        ));
        self::assertFalse(RuleEvaluator::matches(['is_new_days' => 30], $this->product(['created_at' => null])));
    }

    public function testIsNewAcceptsDateTimeObjectFromCatalogRow(): void
    {
        // CatalogService::show returns created_at as a DateTime (model cast),
        // not a string — the evaluator must handle the real row shape.
        $product = $this->product(['created_at' => new \DateTime('-2 days')]);
        self::assertTrue(RuleEvaluator::matches(['is_new_days' => 30], $product));
        $old = $this->product(['created_at' => new \DateTime('-60 days')]);
        self::assertFalse(RuleEvaluator::matches(['is_new_days' => 30], $old));
        $timestamp = $this->product(['created_at' => time() - 2 * 86400]);
        self::assertTrue(RuleEvaluator::matches(['is_new_days' => 30], $timestamp));
    }

    public function testIsNewAcceptsJsonDecodedDateTimeArrayFromCachedCatalogRow(): void
    {
        // Regression (26 Nov 2026, found live): CatalogService::show caches
        // the row for 60s and the cache drivers JSON-encode values, so a
        // cached DateTime comes back as ['date' => '...', 'timezone_type'
        // => 3, 'timezone' => 'UTC']. Before the fix, `is_new_days` only
        // matched on cache misses and silently dropped the new-rule on hits.
        $cached = $this->product(['created_at' => [
            'date' => date('Y-m-d H:i:s.000000', time() - 2 * 86400),
            'timezone_type' => 3,
            'timezone' => 'UTC',
        ]]);
        self::assertTrue(RuleEvaluator::matches(['is_new_days' => 30], $cached));
        $old = $this->product(['created_at' => [
            'date' => date('Y-m-d H:i:s.000000', time() - 60 * 86400),
            'timezone_type' => 3,
            'timezone' => 'UTC',
        ]]);
        self::assertFalse(RuleEvaluator::matches(['is_new_days' => 30], $old));
        $noDate = $this->product(['created_at' => ['timezone_type' => 3, 'timezone' => 'UTC']]);
        self::assertFalse(RuleEvaluator::matches(['is_new_days' => 30], $noDate));
    }

    public function testOnSaleAndDiscount(): void
    {
        self::assertTrue(RuleEvaluator::matches(['on_sale' => true], $this->product()));
        self::assertFalse(RuleEvaluator::matches(['on_sale' => true], $this->product(['compare_at_price_minor' => 80000])));
        self::assertFalse(RuleEvaluator::matches(['on_sale' => true], $this->product(['compare_at_price_minor' => null])));
        self::assertTrue(RuleEvaluator::matches(['on_sale' => false], $this->product(['compare_at_price_minor' => null])));
        self::assertTrue(RuleEvaluator::matches(['discount_min_pct' => 20], $this->product()));
        self::assertFalse(RuleEvaluator::matches(['discount_min_pct' => 21], $this->product()));
        self::assertFalse(RuleEvaluator::matches(['discount_min_pct' => 10], $this->product(['compare_at_price_minor' => null])));
    }

    public function testCategoryAndTag(): void
    {
        self::assertTrue(RuleEvaluator::matches(['category' => 'shirts'], $this->product()));
        self::assertTrue(RuleEvaluator::matches(['category' => ['pants', 'shirts']], $this->product()));
        self::assertFalse(RuleEvaluator::matches(['category' => 'pants'], $this->product()));
        self::assertTrue(RuleEvaluator::matches(['tag' => 'eco'], $this->product()));
        self::assertTrue(RuleEvaluator::matches(['tag' => ['new', 'eco']], $this->product()));
        self::assertFalse(RuleEvaluator::matches(['tag' => 'new'], $this->product()));
        self::assertFalse(RuleEvaluator::matches(['tag' => 'eco'], $this->product(['tags' => []])));
    }

    public function testProductIdsPriceRatingStock(): void
    {
        self::assertTrue(RuleEvaluator::matches(['product_ids' => ['p-1', 'p-2']], $this->product()));
        self::assertFalse(RuleEvaluator::matches(['product_ids' => ['p-9']], $this->product()));
        self::assertTrue(RuleEvaluator::matches(['price_min_minor' => 80000, 'price_max_minor' => 80000], $this->product()));
        self::assertFalse(RuleEvaluator::matches(['price_min_minor' => 80001], $this->product()));
        self::assertFalse(RuleEvaluator::matches(['price_max_minor' => 79999], $this->product()));
        self::assertTrue(RuleEvaluator::matches(['rating_min' => 4.5], $this->product()));
        self::assertFalse(RuleEvaluator::matches(['rating_min' => 4.7], $this->product()));
        self::assertFalse(RuleEvaluator::matches(['rating_min' => 4.0], $this->product(['rating' => null])));
        self::assertTrue(RuleEvaluator::matches(['stock_max' => 5], $this->product()));
        self::assertFalse(RuleEvaluator::matches(['stock_max' => 2], $this->product()));
    }

    public function testBestSellerNeedsExplicitSet(): void
    {
        self::assertTrue(RuleEvaluator::matches(['best_seller' => true], $this->product(), ['p-1']));
        self::assertFalse(RuleEvaluator::matches(['best_seller' => true], $this->product(), ['p-2']));
        self::assertFalse(RuleEvaluator::matches(['best_seller' => true], $this->product(), []));
        self::assertTrue(RuleEvaluator::matches(['best_seller' => false], $this->product(), []));
    }

    public function testAllConditionsMustPass(): void
    {
        $conditions = ['on_sale' => true, 'category' => 'shirts', 'rating_min' => 4.5];
        self::assertTrue(RuleEvaluator::matches($conditions, $this->product()));
        self::assertFalse(RuleEvaluator::matches($conditions, $this->product(['category' => 'pants'])));
    }

    public function testUnknownConditionNeverMatches(): void
    {
        self::assertFalse(RuleEvaluator::matches(['nope' => true], $this->product()));
    }
}
