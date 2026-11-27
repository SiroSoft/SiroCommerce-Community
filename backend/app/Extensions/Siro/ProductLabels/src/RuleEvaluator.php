<?php

declare(strict_types=1);

namespace App\Extensions\Siro\ProductLabels;

/**
 * Pure rules-engine evaluator for product labels.
 *
 * A rule matches a product when EVERY condition in its (already normalized)
 * conditions array passes (AND). The evaluator never touches the database:
 * the caller supplies the product attributes (from CatalogService::show) and,
 * only when a rule needs it, the set of best-seller product ids.
 *
 * Supported conditions (all optional):
 *   is_new_days (int 1-365)        product created within N days
 *   on_sale (bool)                 compare_at_price_minor > price_minor
 *   discount_min_pct (0-100)       discount percent >= value (needs a compare price)
 *   category (string|list<string>) product category slug matches (any)
 *   tag (string|list<string>)      product has any of these tags
 *   product_ids (list<string>)     product id is in the list
 *   price_min_minor / price_max_minor (int >= 0) price bounds (inclusive)
 *   rating_min (0-5)               product rating >= value
 *   stock_max (int >= 0)           product stock <= value (low stock)
 *   best_seller (bool)             product id is in the best-seller set
 *
 * A missing product attribute fails the condition that needs it (fail closed),
 * except `on_sale: false` / `best_seller: false`, which pass when the positive
 * fact is absent. Unknown condition keys are rejected by the service at write
 * time, never here.
 */
final class RuleEvaluator
{
    /** @var list<string> */
    public const CONDITION_KEYS = [
        'is_new_days',
        'on_sale',
        'discount_min_pct',
        'category',
        'tag',
        'product_ids',
        'price_min_minor',
        'price_max_minor',
        'rating_min',
        'stock_max',
        'best_seller',
    ];

    /**
     * @param array<string, mixed> $conditions Normalized conditions.
     * @param array<string, mixed> $product Product attributes (CatalogService row shape).
     * @param list<string> $bestSellerIds Product ids ranked best sellers for the scope.
     */
    public static function matches(array $conditions, array $product, array $bestSellerIds = []): bool
    {
        foreach ($conditions as $key => $value) {
            if (!self::conditionMatches($key, $value, $product, $bestSellerIds)) {
                return false;
            }
        }
        return true;
    }

    /**
     * @param array<string, mixed> $product
     * @param list<string> $bestSellerIds
     */
    private static function conditionMatches(string $key, mixed $value, array $product, array $bestSellerIds): bool
    {
        return match ($key) {
            'is_new_days' => self::isNew($product, $value),
            'on_sale' => self::onSale($product) === $value,
            'discount_min_pct' => self::floatAtLeast(self::discountPct($product), $value),
            'category' => self::matchesAny($value, self::stringValue($product['category'] ?? null)),
            'tag' => self::matchesAnyTag($value, $product['tags'] ?? null),
            'product_ids' => is_array($value) && in_array(self::stringValue($product['id'] ?? null), $value, true),
            'price_min_minor' => self::intAtLeast(self::intValue($product['price_minor'] ?? null), $value),
            'price_max_minor' => self::intAtMost(self::intValue($product['price_minor'] ?? null), $value),
            'rating_min' => self::floatAtLeast(self::floatValue($product['rating'] ?? null), $value),
            'stock_max' => self::intAtMost(self::intValue($product['stock'] ?? null), $value),
            'best_seller' => in_array(self::stringValue($product['id'] ?? null), $bestSellerIds, true) === $value,
            default => false,
        };
    }

    private static function intAtLeast(?int $actual, mixed $threshold): bool
    {
        $min = self::intValue($threshold);
        return $actual !== null && $min !== null && $actual >= $min;
    }

    private static function intAtMost(?int $actual, mixed $threshold): bool
    {
        $max = self::intValue($threshold);
        return $actual !== null && $max !== null && $actual <= $max;
    }

    private static function floatAtLeast(?float $actual, mixed $threshold): bool
    {
        $min = self::floatValue($threshold);
        return $actual !== null && $min !== null && $actual >= $min;
    }

    /** @param array<string, mixed> $product */
    private static function isNew(array $product, mixed $days): bool
    {
        $daysInt = self::intValue($days);
        if ($daysInt === null) {
            return false;
        }
        $created = self::createdTimestamp($product['created_at'] ?? null);
        if ($created === null) {
            return false;
        }
        return $created >= time() - $daysInt * 86400;
    }

    /**
     * Catalog rows may carry created_at as a DateTime (model cast), a
     * datetime string, or a unix timestamp — accept all three. The catalog
     * read is cached (CatalogService::show, 60s) and the cache drivers
     * JSON-encode values, so a cached DateTime comes back as the
     * json_decode() array shape ['date' => 'Y-m-d H:i:s.u', ...] — accept
     * that too, otherwise `is_new_days` silently stops matching on cache
     * hits (verified live 26 Nov 2026: new-rule matched only on cache
     * misses until this fix).
     */
    private static function createdTimestamp(mixed $value): ?int
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->getTimestamp();
        }
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && trim($value) !== '') {
            $parsed = strtotime(trim($value));
            return $parsed === false ? null : $parsed;
        }
        if (is_array($value) && is_string($value['date'] ?? null) && trim($value['date']) !== '') {
            $parsed = strtotime(trim($value['date']));
            return $parsed === false ? null : $parsed;
        }
        return null;
    }

    /** @param array<string, mixed> $product */
    private static function onSale(array $product): bool
    {
        $price = self::intValue($product['price_minor'] ?? null);
        $compare = self::intValue($product['compare_at_price_minor'] ?? null);
        if ($price === null || $compare === null) {
            return false;
        }
        return $compare > $price;
    }

    /** @param array<string, mixed> $product */
    private static function discountPct(array $product): ?float
    {
        $price = self::intValue($product['price_minor'] ?? null);
        $compare = self::intValue($product['compare_at_price_minor'] ?? null);
        if ($price === null || $compare === null || $compare <= 0 || $compare <= $price) {
            return null;
        }
        return ($compare - $price) / $compare * 100.0;
    }

    private static function matchesAny(mixed $wanted, string $actual): bool
    {
        if ($actual === '') {
            return false;
        }
        $list = is_string($wanted) ? [$wanted] : (is_array($wanted) ? $wanted : []);
        foreach ($list as $item) {
            if ($item === $actual) {
                return true;
            }
        }
        return false;
    }

    private static function matchesAnyTag(mixed $wanted, mixed $tags): bool
    {
        if (!is_array($tags)) {
            return false;
        }
        $have = array_values(array_filter($tags, static fn (mixed $tag): bool => is_string($tag) && $tag !== ''));
        if ($have === []) {
            return false;
        }
        $list = is_string($wanted) ? [$wanted] : (is_array($wanted) ? $wanted : []);
        foreach ($list as $tag) {
            if (in_array($tag, $have, true)) {
                return true;
            }
        }
        return false;
    }

    private static function intValue(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_float($value) && floor($value) === $value) {
            return (int) $value;
        }
        if (is_string($value) && preg_match('/^-?\d+$/', trim($value)) === 1) {
            return (int) trim($value);
        }
        return null;
    }

    private static function floatValue(mixed $value): ?float
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }
        if (is_string($value) && is_numeric(trim($value))) {
            return (float) trim($value);
        }
        return null;
    }

    private static function stringValue(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }
        return '';
    }
}
