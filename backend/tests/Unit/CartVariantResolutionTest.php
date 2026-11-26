<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\ProductVariant;
use App\Repositories\CartRepository;
use Tests\TestCase;

final class CartVariantResolutionTest extends TestCase
{
    public function testResolveVariantAcceptsVariantAndProductIds(): void
    {
        ProductVariant::create(['id' => 'vr-res-2', 'product_id' => 'prod-res-1', 'sku' => 'RES-2', 'price_minor' => 200]);
        ProductVariant::create(['id' => 'vr-res-1', 'product_id' => 'prod-res-1', 'sku' => 'RES-1', 'price_minor' => 100]);

        $repository = new CartRepository();
        self::assertSame('vr-res-1', $repository->resolveVariant('vr-res-1'));
        self::assertSame('vr-res-1', $repository->resolveVariant('prod-res-1'));
        self::assertNull($repository->resolveVariant('missing'));
    }
}
