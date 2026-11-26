<?php

declare(strict_types=1);

namespace App\Resources;

use App\Support\ImageDerivativeService;
use Siro\Core\Resource;

final class ProductResource extends Resource
{
    public function toArray(): array
    {
        $result = $this->pick($this->data, ['id', 'name', 'slug', 'brand_id', 'category', 'category_name', 'vendor_id', 'vendor', 'description', 'price', 'price_minor', 'compare_at_price', 'compare_at_price_minor', 'money', 'currency', 'image', 'image_alt', 'rating', 'review_count', 'tags', 'stock', 'allow_backorder', 'is_preorder', 'type', 'product_type', 'status', 'min_tier', 'meta_title', 'meta_description', 'created_at', 'updated_at']);
        if (isset($result['image']) && is_string($result['image'])) {
            $result['image'] = ImageDerivativeService::publicUrl($result['image']);
        }
        if (!array_key_exists('vendor', $result)) $result['vendor'] = null;
        if (!array_key_exists('vendor_id', $result)) $result['vendor_id'] = null;
        if (array_key_exists('is_preorder', $result)) $result['is_preorder'] = (bool) $result['is_preorder'];
        foreach (['options', 'media', 'images', 'variants', 'variant_options', 'quantity_pricing', 'option_pricing', 'bundle_components', 'grouped_items', 'delivery', 'components', 'digital_assets', 'attributes', 'dependencies', 'booking_slots', 'booking', 'flash_sales', 'stock_by_source', 'member_prices', 'subscription_plans'] as $key) {
            if (array_key_exists($key, $this->data)) {
                $result[$key] = $this->data[$key];
            }
        }
        foreach (['images', 'media'] as $key) {
            if (isset($result[$key]) && is_array($result[$key])) {
                $result[$key] = $this->withImageDerivatives($result[$key]);
            }
        }
        return $result;
    }

    /**
     * Keep the legacy `{url, alt, role}` shape and add optional responsive
     * derivatives when the source row still carries `variants_json`.
     *
     * @param array<mixed> $images
     * @return array<mixed>
     */
    private function withImageDerivatives(array $images): array
    {
        foreach ($images as $index => $image) {
            if (!is_array($image)) continue;
            $raw = $image['variants'] ?? $image['variants_json'] ?? null;
            if (is_string($raw) && $raw !== '') {
                $decoded = json_decode($raw, true);
                $raw = is_array($decoded) ? $decoded : null;
            }
            unset($image['variants_json']);
            if (isset($image['url']) && is_string($image['url'])) {
                $image['url'] = ImageDerivativeService::publicUrl($image['url']);
            }
            $variants = [];
            if (is_array($raw)) {
                foreach ($raw as $variant) {
                    if (!is_array($variant)) continue;
                    $width = $variant['w'] ?? null;
                    $url = $variant['url'] ?? null;
                    $format = $variant['format'] ?? 'webp';
                    if (!is_int($width) || $width < 1 || !is_string($url) || trim($url) === '' || !is_string($format)) continue;
                    $variants[] = ['w' => $width, 'url' => ImageDerivativeService::publicUrl($url), 'format' => $format];
                }
            }
            if ($variants !== []) {
                $image['variants'] = $variants;
            } else {
                unset($image['variants']);
            }
            if (!isset($image['placeholder']) || !is_string($image['placeholder']) || trim($image['placeholder']) === '') {
                unset($image['placeholder']);
            }
            $images[$index] = $image;
        }
        return $images;
    }

    /**
     * @param array<string, mixed> $data
     * @param array<int, string> $fields
     * @return array<string, mixed>
     */
    private function pick(array $data, array $fields): array
    {
        $result = [];
        foreach ($fields as $field) {
            if (array_key_exists($field, $data)) $result[$field] = $data[$field];
        }
        return $result;
    }
}
