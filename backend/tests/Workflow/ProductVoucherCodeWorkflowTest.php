<?php

declare(strict_types=1);

namespace Tests\Workflow;

use App\Modules\Catalog\Application\ProductLifecycleService;
use App\Repositories\ProductRepository;
use App\Support\CommerceScope;
use Tests\TestCase;

final class ProductVoucherCodeWorkflowTest extends TestCase
{
    public function testVoucherCodeReadCannotCrossCommerceScope(): void
    {
        $product = (new ProductRepository())->store(['id' => 'workflow-voucher-product', 'name' => 'Workflow voucher', 'slug' => 'workflow-voucher', 'type' => 'voucher', 'status' => 'draft', 'store_id' => 'store-a', 'channel_id' => 'web', 'locale' => 'en', 'currency' => 'USD']);
        (new ProductLifecycleService())->addVoucher($product['id'], ['code' => 'WORKFLOW-1'], 'workflow-replay-key');

        self::assertNotNull((new ProductRepository())->findInScope($product['id'], new CommerceScope('store-a', 'web', 'en', 'USD')));
        self::assertNull((new ProductRepository())->findInScope($product['id'], new CommerceScope('store-b', 'web', 'en', 'USD')));
        self::assertCount(1, (new ProductLifecycleService())->vouchers($product['id']));
    }
}
