<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\Cart;
use App\Models\CartLine;
use App\Models\ProductVariant;
use App\Models\Product;
use App\Models\ProductComponent;
use App\Models\ProductImage;
use App\Models\Vendor;
use App\Repositories\Concerns\CreatesModels;
use App\Support\Arr;

final class CartRepository
{
    use CreatesModels;

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function createCart(array $data): array
    {
        return self::createRow(Cart::class, $data);
    }

    /** @return array<string, mixed>|null */
    public function findCart(string $id): ?array
    {
        $row = Cart::find($id);
        if ($row === null) {
            return null;
        }
        $data = $row->toArray();
        if (($data['state'] ?? null) === 'open' && isset($data['expires_at']) && is_string($data['expires_at']) && strtotime($data['expires_at']) <= time()) {
            $row->setAttribute('state', 'expired');
            $row->save();
            $data['state'] = 'expired';
        }
        /** @var array<string, mixed> $out */
        $out = $data;
        return $out;
    }

    public function accessTokenIsValid(string $id, string $token): bool
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
            return false;
        }
        $cart = $this->findCart($id);
        if ($cart === null || ($cart['state'] ?? null) !== 'open') {
            return false;
        }
        $hash = $cart['access_token_hash'] ?? null;
        return is_string($hash) && hash_equals($hash, hash('sha256', $token));
    }

    public function accessTokenIsValidForReplay(string $id, string $token): bool
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $token)) return false;
        $cart = $this->findCart($id);
        if ($cart === null || ($cart['state'] ?? null) !== 'converted') return false;
        $hash = $cart['access_token_hash'] ?? null;
        return is_string($hash) && hash_equals($hash, hash('sha256', $token));
    }

    public function setCartState(string $id, string $state): void
    {
        $row = Cart::find($id);
        if ($row !== null) {
            $row->setAttribute('state', $state);
            $row->save();
        }
    }

    /** @return array<int, array<string, mixed>> */
    public function lines(string $cartId): array
    {
        $rows = CartLine::query()->where('cart_id', $cartId)->get();
        return array_map(static function ($m): array {
            /** @var array<string, mixed> $out */
            $out = $m->toArray();
            $variant = ProductVariant::find(Arr::str($out, 'variant_id'));
            if ($variant !== null) {
                $variantRow = $variant->toArray();
                $product = Product::find(Arr::str($variantRow, 'product_id'));
                $productRow = $product?->toArray() ?? [];
                $out['product_id'] = $variantRow['product_id'] ?? null;
                $out['product_type'] = Arr::str($productRow, 'type', 'simple');
                $out['sku'] = Arr::str($variantRow, 'sku');
                $out['name'] = Arr::str($productRow, 'name');
                $vendorId = Arr::nullableStr($productRow, 'vendor_id');
                $out['vendor_id'] = $vendorId;
                if ($vendorId !== null) {
                    $vendor = Vendor::find($vendorId);
                    $out['vendor_commission_bps'] = $vendor === null ? 0 : Arr::int(Arr::row($vendor), 'commission_bps');
                }
                if (Arr::str($out, 'product_type') === 'bundle') {
                    $components = ProductComponent::query()->where('product_id', Arr::str($variantRow, 'product_id'))->get();
                    $out['bundle_pricing_mode'] = Arr::str($productRow, 'bundle_pricing_mode', 'component_sum');
                    $out['bundle_price_minor'] = $productRow['bundle_price_minor'] ?? null;
                    $out['components'] = array_map(static function ($component): array {
                        $row = $component->toArray();
                        $componentVariant = ProductVariant::find(Arr::str($row, 'component_variant_id'));
                        $componentRow = $componentVariant?->toArray() ?? [];
                        $row['unit_price_minor'] = $componentRow['price_minor'] ?? null;
                        $row['sku'] = Arr::str($componentRow, 'sku');
                        return $row;
                    }, $components);
                }
            }
            return $out;
        }, $rows);
    }

    /** @return array<string, mixed> */
    public function addLine(string $cartId, string $variantId, int $qty, int $unitPriceMinor, array $extra = []): array
    {
        $slotStart = Arr::nullableStr($extra, 'slot_start');
        $bookingSlotId = Arr::nullableStr($extra, 'booking_slot_id');
        $flashSaleId = Arr::nullableStr($extra, 'flash_sale_id');
        $query = CartLine::query()->where('cart_id', $cartId)->where('variant_id', $variantId);
        if ($slotStart !== null) $query = $query->where('slot_start', $slotStart);
        else $query = $query->whereNull('slot_start');
        if ($flashSaleId !== null) $query = $query->where('flash_sale_id', $flashSaleId);
        else $query = $query->whereNull('flash_sale_id');
        $existing = $query->first();
        if ($existing !== null) {
            /** @var array<string, mixed> $current */
            $current = $existing->toArray();
            $existing->update(['qty' => Arr::int($current, 'qty') + $qty]);
            /** @var array<string, mixed> $out */
            $out = $existing->toArray();
            return $out;
        }
        $data = [
            'id' => bin2hex(random_bytes(13)),
            'cart_id' => $cartId,
            'variant_id' => $variantId,
            'qty' => $qty,
            'unit_price_minor' => $unitPriceMinor,
        ];
        if ($slotStart !== null) $data['slot_start'] = $slotStart;
        if ($bookingSlotId !== null) $data['booking_slot_id'] = $bookingSlotId;
        if ($flashSaleId !== null) $data['flash_sale_id'] = $flashSaleId;
        return self::createRow(CartLine::class, $data);
    }

    public function variantPrice(string $variantId): ?int
    {
        $row = ProductVariant::query()->where('id', $variantId)->first();
        if ($row === null) {
            return null;
        }
        /** @var array<string, mixed> $out */
        $out = $row->toArray();
        $price = $out['price_minor'] ?? null;
        return is_int($price) ? $price : null;
    }

    /** @return array<string, string> product id to primary gallery image url */
    public function primaryImages(array $productIds): array
    {
        $out = [];
        if ($productIds === []) return $out;
        foreach (ProductImage::query()->whereIn('product_id', array_values(array_unique($productIds)))->orderBy('sort')->get() as $image) {
            $row = Arr::row($image);
            $owner = Arr::str($row, 'product_id');
            if ($owner !== '' && !isset($out[$owner]) && Arr::str($row, 'url') !== '') $out[$owner] = Arr::str($row, 'url');
        }
        return $out;
    }

    public function setLineQuantity(string $cartId, string $lineId, int $quantity): void
    {
        $line = CartLine::query()->where('id', $lineId)->where('cart_id', $cartId)->first();
        if ($line === null) throw new \RuntimeException('Cart line not found');
        $line->update(['qty' => $quantity]);
    }

    public function removeLine(string $cartId, string $lineId): void
    {
        $line = CartLine::query()->where('id', $lineId)->where('cart_id', $cartId)->first();
        if ($line === null) throw new \RuntimeException('Cart line not found');
        $line->delete();
    }

    /**
     * Accepts a variant id or a product id (resolving to the product's
     * default variant) so storefront handoffs can reference products.
     */
    public function resolveVariant(string $id): ?string
    {
        if (ProductVariant::query()->where('id', $id)->first() !== null) return $id;
        $fallback = ProductVariant::query()->where('product_id', $id)->orderBy('id')->first();
        if ($fallback === null) return null;
        /** @var array<string, mixed> $row */
        $row = $fallback->toArray();
        $resolved = $row['id'] ?? null;
        return is_string($resolved) && $resolved !== '' ? $resolved : null;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function createVariant(array $data): array
    {
        return self::createRow(ProductVariant::class, $data);
    }
}
