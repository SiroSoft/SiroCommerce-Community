<?php

declare(strict_types=1);

namespace App\Modules\Search\Application;

use App\Support\Arr;

/** Maps one product row (plus its variants) to the Meilisearch document shape. */
final class CatalogIndexDocumentBuilder
{
    /**
     * @param array<string, mixed> $product
     * @param array<int, array<string, mixed>> $variants
     * @return array<string, mixed>
     */
    public function build(array $product, array $variants = [], string $category = '', bool $inStock = false): array
    {
        $decodedTags = json_decode(Arr::str($product, 'tags_json', '[]'), true);
        $tags = is_array($decodedTags) ? $decodedTags : [];
        $tags = array_values(array_unique(array_filter(
            array_map(static fn (mixed $tag): string => is_string($tag) ? mb_strtolower(trim($tag)) : '', $tags),
            static fn (string $tag): bool => $tag !== ''
        )));

        $price = null;
        foreach ($variants as $variant) {
            if (!is_array($variant)) continue;
            $value = Arr::int($variant, 'price_minor', -1);
            if ($value < 0) continue;
            if ($price === null || $value < $price) $price = $value;
        }

        $minTier = Arr::nullableStr($product, 'min_tier');
        $brand = mb_strtolower(trim(Arr::str($product, 'brand_id')));
        $slug = Arr::str($product, 'slug');

        return [
            'id' => Arr::str($product, 'id'),
            'name' => Arr::str($product, 'name'),
            'description' => Arr::str($product, 'description'),
            'tags' => $tags,
            'category' => mb_strtolower(trim($category)),
            'brand' => $brand,
            'status' => Arr::str($product, 'status'),
            'archived' => Arr::str($product, 'archived_at') !== '',
            'min_tier' => $minTier === null || $minTier === '' ? null : mb_strtolower($minTier),
            'store_id' => Arr::str($product, 'store_id'),
            'channel_id' => Arr::str($product, 'channel_id'),
            'locale' => Arr::str($product, 'locale'),
            'currency' => Arr::str($product, 'currency'),
            'price_minor' => $price ?? 0,
            'in_stock' => $inStock,
            'created_at' => Arr::str($product, 'created_at'),
            'slug' => $slug,
        ];
    }
}
