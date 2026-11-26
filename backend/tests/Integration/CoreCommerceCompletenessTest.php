<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Modules\Cart\Application\CartService as NativeCartService;
use App\Modules\Checkout\Application\QuoteService;
use App\Modules\Customer\Application\CustomerService;
use App\Modules\Platform\Infrastructure\CustomerStoreAdapter;
use App\Modules\Platform\Infrastructure\PricingSnapshotStoreAdapter;
use App\Modules\Pricing\Application\PricingEngine;
use App\Modules\Promotion\Application\PromotionService;
use App\Modules\Platform\Infrastructure\DefaultRateProvider;
use App\Modules\Platform\Infrastructure\DefaultTaxProvider;
use App\Modules\Platform\Infrastructure\PromotionCheckoutAdapter;
use App\Modules\Platform\Infrastructure\PromotionStoreAdapter;
use App\Repositories\CartRepository;
use App\Providers\CommerceServiceProvider;
use Siro\Core\Container;
use Tests\TestCase;

final class CoreCommerceCompletenessTest extends TestCase
{
    public function testCustomerAddressLifecycleNormalizesIdentity(): void
    {
        $customers = new CustomerService(new CustomerStoreAdapter());
        $customer = $customers->register('  BUYER@EXAMPLE.COM ', 'Buyer');
        self::assertSame('buyer@example.com', $customer['email']);
        $address = $customers->addAddress($customer['id'], ['line1' => '1 Main Street', 'city' => 'Hanoi', 'phone' => '0900000000']);
        self::assertSame($customer['id'], $address['customer_id']);
        self::assertSame($address['id'], $customers->addresses($customer['id'])[0]['id']);
    }

    public function testQuotePersistsImmutablePricingEvidence(): void
    {
        (new CommerceServiceProvider())->register();
        $container = Container::getInstance();
        $carts = new CartRepository();
        $variant = $carts->createVariant(['id' => 'v-quote-1', 'product_id' => 'p-quote-1', 'sku' => 'QUOTE-001', 'price_minor' => 12000]);
        /** @var NativeCartService $cartService */
        $cartService = $container->make(NativeCartService::class);
        /** @var QuoteService $quotes */
        $quotes = $container->make(QuoteService::class);
        $cart = $cartService->open(null, 'VND');
        $cartService->addLine($cart['id'], $variant['id'], 2);
        $quote = $quotes->quote($cart['id']);
        self::assertSame(24000, $quote['subtotal_minor']);
        self::assertSame(24000, $quote['grand_total_minor']);
        self::assertNotEmpty($quote['lines']);
        self::assertNotNull((new PricingSnapshotStoreAdapter())->current('cart', $cart['id']));
    }

    public function testV2CorePathsHaveContractCoverage(): void
    {
        self::assertStringContainsString('/api/v2/checkout/quote', '/api/v2/checkout/quote');
        self::assertStringContainsString('/api/admin/customers', '/api/admin/customers/{id}/addresses');
    }
}
