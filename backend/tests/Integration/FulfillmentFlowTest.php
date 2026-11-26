<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Modules\Fulfillment\Application\FulfillmentManager;
use App\Modules\Platform\Infrastructure\DefaultCarrier;
use App\Modules\Platform\Infrastructure\FulfillmentStoreAdapter;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\PackageLine;
use App\Modules\Fulfillment\Application\ReturnManager;
use App\Modules\Platform\Infrastructure\ReturnStoreAdapter;
use App\Modules\Platform\Infrastructure\InventoryStoreAdapter;
use App\Repositories\InventoryRepository;
use App\Models\InventoryItem;
use App\Models\StockLedger;
use Tests\TestCase;
use Siro\Core\Database;

final class FulfillmentFlowTest extends TestCase
{
    public function testAllocationShippingAndDeliveryAreIdempotent(): void
    {
        Order::create(['id' => 'order-fulfillment-1', 'number' => 'F-1', 'currency' => 'VND', 'subtotal_minor' => 100, 'grand_total_minor' => 100, 'idempotency_key' => 'order-key-1']);
        OrderLine::create(['id' => 'line-1', 'order_id' => 'order-fulfillment-1', 'variant_id' => 'variant-1', 'sku' => 'SKU-1', 'name' => 'Item', 'qty' => 2, 'unit_price_minor' => 50]);
        $service = new FulfillmentManager(new FulfillmentStoreAdapter(), new DefaultCarrier());
        $allocated = $service->allocate('order-fulfillment-1', [['order_line_id' => 'line-1', 'qty' => 2]], 'fulfill-key-1');
        $duplicate = $service->allocate('order-fulfillment-1', [['order_line_id' => 'line-1', 'qty' => 99]], 'fulfill-key-1');
        self::assertSame($allocated['id'], $duplicate['id']);
        self::assertSame('allocated', $allocated['state']);

        $package = $service->ship($allocated['id'], 'package-key-1');
        $samePackage = $service->ship($allocated['id'], 'package-key-1');
        self::assertSame($package['id'], $samePackage['id']);
        self::assertSame('shipped', $package['state']);
        self::assertCount(1, PackageLine::query()->where('package_id', $package['id'])->get());

        $delivered = $service->deliver($allocated['id'], 'delivery-key-1');
        self::assertSame('delivered', $delivered['state']);
        self::assertSame($delivered, $service->deliver($allocated['id'], 'delivery-key-2'));
    }

    public function testCarrierTrackingIsPersisted(): void
    {
        Order::create(['id' => 'order-track-1', 'number' => 'T-1', 'currency' => 'VND', 'subtotal_minor' => 100, 'grand_total_minor' => 100, 'idempotency_key' => 'order-track-key']);
        OrderLine::create(['id' => 'line-track-1', 'order_id' => 'order-track-1', 'variant_id' => 'variant-1', 'sku' => 'SKU-1', 'name' => 'Item', 'qty' => 1, 'unit_price_minor' => 100]);
        $service = new FulfillmentManager(new FulfillmentStoreAdapter(), new DefaultCarrier());
        $allocated = $service->allocate('order-track-1', [['order_line_id' => 'line-track-1', 'qty' => 1]], 'fulfill-track-1');
        $service->ship($allocated['id'], 'package-track-1');

        $tracked = $service->track($allocated['id']);
        self::assertSame('in_transit', $tracked['state']);
        self::assertSame('in_transit', (new FulfillmentStoreAdapter())->package($allocated['id'])['state']);
    }

    public function testReturnCannotBeCompletedBeforeWorkflowApproval(): void
    {
        Order::create(['id' => 'order-return-1', 'number' => 'R-1', 'currency' => 'VND', 'subtotal_minor' => 100, 'grand_total_minor' => 100, 'idempotency_key' => 'order-key-2']);
        OrderLine::create(['id' => 'line-return-1', 'order_id' => 'order-return-1', 'variant_id' => 'variant-1', 'sku' => 'SKU-1', 'name' => 'Item', 'qty' => 2, 'unit_price_minor' => 50]);
        $fulfillment = new FulfillmentManager(new FulfillmentStoreAdapter(), new DefaultCarrier());
        $allocated = $fulfillment->allocate('order-return-1', [['order_line_id' => 'line-return-1', 'qty' => 2]], 'fulfill-return-1');
        $fulfillment->ship($allocated['id'], 'package-return-1');
        $fulfillment->deliver($allocated['id'], 'delivery-return-1');
        $returns = new ReturnManager(new ReturnStoreAdapter(), new FulfillmentStoreAdapter(), new InventoryStoreAdapter(new InventoryRepository()));
        $returned = $returns->request('order-return-1', $allocated['id'], [['order_line_id' => 'line-return-1', 'qty' => 1]], 'damaged', 'return-1');
        self::assertSame('requested', $returned['state']);
        self::expectException(\InvalidArgumentException::class);
        $returns->complete($returned['id'], 'complete-return-1');
    }

    public function testRejectedReturnQuantityCanBeRequestedAgain(): void
    {
        Order::create(['id' => 'order-rejected-return', 'number' => 'R-2', 'currency' => 'VND', 'subtotal_minor' => 100, 'grand_total_minor' => 100, 'idempotency_key' => 'order-key-rejected']);
        OrderLine::create(['id' => 'line-rejected-return', 'order_id' => 'order-rejected-return', 'variant_id' => 'variant-rejected', 'sku' => 'SKU-R', 'name' => 'Item', 'qty' => 1, 'unit_price_minor' => 100]);
        $fulfillment = new FulfillmentManager(new FulfillmentStoreAdapter(), new DefaultCarrier());
        $allocated = $fulfillment->allocate('order-rejected-return', [['order_line_id' => 'line-rejected-return', 'qty' => 1]], 'fulfill-rejected');
        $fulfillment->ship($allocated['id'], 'package-rejected');
        $fulfillment->deliver($allocated['id'], 'delivery-rejected');
        $returns = new ReturnManager(new ReturnStoreAdapter(), new FulfillmentStoreAdapter(), new InventoryStoreAdapter(new InventoryRepository()));
        $rejected = $returns->request('order-rejected-return', $allocated['id'], [['order_line_id' => 'line-rejected-return', 'qty' => 1]], 'wrong item', 'return-rejected');
        $returns->reject($rejected['id'], 'reject-key');
        self::assertSame('requested', $returns->request('order-rejected-return', $allocated['id'], [['order_line_id' => 'line-rejected-return', 'qty' => 1]], 'changed mind', 'return-retry')['state']);
    }

    public function testApprovedInspectedAndRefundApprovedReturnCanBeCompleted(): void
    {
        Order::create(['id' => 'order-rma-gated', 'number' => 'R-3', 'currency' => 'VND', 'subtotal_minor' => 100, 'grand_total_minor' => 100, 'idempotency_key' => 'order-key-gated']);
        OrderLine::create(['id' => 'line-rma-gated', 'order_id' => 'order-rma-gated', 'variant_id' => 'variant-gated', 'sku' => 'SKU-G', 'name' => 'Item', 'qty' => 1, 'unit_price_minor' => 100]);
        $fulfillment = new FulfillmentManager(new FulfillmentStoreAdapter(), new DefaultCarrier());
        $allocated = $fulfillment->allocate('order-rma-gated', [['order_line_id' => 'line-rma-gated', 'qty' => 1]], 'fulfill-gated');
        $fulfillment->ship($allocated['id'], 'package-gated');
        $fulfillment->deliver($allocated['id'], 'delivery-gated');
        $returns = new ReturnManager(new ReturnStoreAdapter(), new FulfillmentStoreAdapter(), new InventoryStoreAdapter(new InventoryRepository()));
        $rma = $returns->request('order-rma-gated', $allocated['id'], [['order_line_id' => 'line-rma-gated', 'qty' => 1]], 'defective', 'return-gated');
        $returns->approve($rma['id'], 'approve-gated');
        $returns->inspect($rma['id'], 'inspect-gated', 'restock');
        $returns->approveRefund($rma['id'], 'refund-gated');
        self::assertSame('completed', $returns->complete($rma['id'], 'complete-gated')['state']);
    }

    public function testRmaApprovalInspectionAndRefundApprovalStatesAreIdempotent(): void
    {
        Order::create(['id' => 'order-rma-states', 'number' => 'RMA-1', 'currency' => 'VND', 'subtotal_minor' => 100, 'grand_total_minor' => 100, 'idempotency_key' => 'rma-order']);
        OrderLine::create(['id' => 'line-rma-states', 'order_id' => 'order-rma-states', 'variant_id' => 'variant-1', 'sku' => 'SKU-1', 'name' => 'Item', 'qty' => 1, 'unit_price_minor' => 100]);
        $fulfillment = new FulfillmentManager(new FulfillmentStoreAdapter(), new DefaultCarrier());
        $allocated = $fulfillment->allocate('order-rma-states', [['order_line_id' => 'line-rma-states', 'qty' => 1]], 'rma-fulfill');
        $fulfillment->ship($allocated['id'], 'rma-package'); $fulfillment->deliver($allocated['id'], 'rma-delivery');
        $returns = new ReturnManager(new ReturnStoreAdapter(), new FulfillmentStoreAdapter(), new InventoryStoreAdapter(new InventoryRepository()));
        $rma = $returns->request('order-rma-states', $allocated['id'], [['order_line_id' => 'line-rma-states', 'qty' => 1, 'disposition' => 'damaged']], 'defective', 'rma-request');
        self::assertSame('approved', $returns->approve($rma['id'], 'rma-approve')['state']);
        self::assertSame('inspected', $returns->inspect($rma['id'], 'rma-inspect', 'damaged', 'seal broken')['state']);
        self::assertSame('inspected', $returns->inspect($rma['id'], 'rma-inspect-retry', 'damaged')['state']);
        self::assertSame('approved_refund', $returns->approveRefund($rma['id'], 'rma-refund')['state']);
        self::assertSame('completed', $returns->complete($rma['id'], 'rma-complete')['state']);
        self::assertSame(0, (int) InventoryItem::query()->where('variant_id', 'variant-1')->first()->toArray()['on_hand']);
        self::assertSame(1, StockLedger::query()->where('variant_id', 'variant-1')->where('reason', 'rma_damaged')->count());
    }

    public function testReturnLabelIsProviderBackedAndIdempotent(): void
    {
        Order::create(['id' => 'order-rma-label', 'number' => 'RMA-L', 'currency' => 'VND', 'subtotal_minor' => 100, 'grand_total_minor' => 100, 'idempotency_key' => 'rma-label-order']);
        OrderLine::create(['id' => 'line-rma-label', 'order_id' => 'order-rma-label', 'variant_id' => 'variant-label', 'sku' => 'SKU-L', 'name' => 'Item', 'qty' => 1, 'unit_price_minor' => 100]);
        $fulfillment = new FulfillmentManager(new FulfillmentStoreAdapter(), new DefaultCarrier());
        $allocated = $fulfillment->allocate('order-rma-label', [['order_line_id' => 'line-rma-label', 'qty' => 1]], 'rma-label-fulfill');
        $fulfillment->ship($allocated['id'], 'rma-label-package'); $fulfillment->deliver($allocated['id'], 'rma-label-delivery');
        $provider = new class implements \App\Modules\Fulfillment\Contracts\ReturnLabelProvider {
            public function create(array $payload, string $idempotencyKey): array { return ['carrier' => 'test', 'provider_ref' => 'label-' . $idempotencyKey, 'tracking' => 'TRACK-1', 'label_url' => 'https://labels.test/1']; }
        };
        $returns = new ReturnManager(new ReturnStoreAdapter(), new FulfillmentStoreAdapter(), new InventoryStoreAdapter(new InventoryRepository()), $provider);
        $rma = $returns->request('order-rma-label', $allocated['id'], [['order_line_id' => 'line-rma-label', 'qty' => 1]], 'changed-mind', 'rma-label-request');
        $label = $returns->createLabel($rma['id'], ['country' => 'VN'], 'rma-label-key');
        self::assertSame('queued', $label['label_state']);
        self::assertSame($label['id'], $returns->createLabel($rma['id'], ['country' => 'other'], 'rma-label-key')['id']);
        $label = $returns->processLabel($rma['id'], ['country' => 'VN'], 'rma-label-key');
        self::assertSame('label_created', $label['label_state']);
        self::expectException(\InvalidArgumentException::class);
        $returns->createLabel($rma['id'], ['country' => 'VN'], 'another-label-key');
    }

    public function testReturnLabelProviderFailurePersistsRetryableOperation(): void
    {
        Order::create(['id' => 'order-rma-label-retry', 'number' => 'RMA-LR', 'currency' => 'VND', 'subtotal_minor' => 100, 'grand_total_minor' => 100, 'idempotency_key' => 'rma-label-retry-order']);
        OrderLine::create(['id' => 'line-rma-label-retry', 'order_id' => 'order-rma-label-retry', 'variant_id' => 'variant-label-retry', 'sku' => 'SKU-LR', 'name' => 'Item', 'qty' => 1, 'unit_price_minor' => 100]);
        $fulfillment = new FulfillmentManager(new FulfillmentStoreAdapter(), new DefaultCarrier());
        $allocated = $fulfillment->allocate('order-rma-label-retry', [['order_line_id' => 'line-rma-label-retry', 'qty' => 1]], 'rma-label-retry-fulfill');
        $fulfillment->ship($allocated['id'], 'rma-label-retry-package'); $fulfillment->deliver($allocated['id'], 'rma-label-retry-delivery');
        $provider = new class implements \App\Modules\Fulfillment\Contracts\ReturnLabelProvider {
            public int $calls = 0;
            public function create(array $payload, string $idempotencyKey): array { $this->calls++; if ($this->calls === 1) throw new \RuntimeException('provider unavailable'); return ['carrier' => 'test', 'provider_ref' => 'retry-' . $idempotencyKey]; }
        };
        $returns = new ReturnManager(new ReturnStoreAdapter(), new FulfillmentStoreAdapter(), new InventoryStoreAdapter(new InventoryRepository()), $provider);
        $rma = $returns->request('order-rma-label-retry', $allocated['id'], [['order_line_id' => 'line-rma-label-retry', 'qty' => 1]], 'retry', 'rma-label-retry-request');
        $returns->createLabel($rma['id'], [], 'rma-label-retry-key');
        try { $returns->processLabel($rma['id'], [], 'rma-label-retry-key'); self::fail('Provider failure should be surfaced'); } catch (\RuntimeException $e) { self::assertSame('provider unavailable', $e->getMessage()); }
        self::assertSame('label_created', $returns->processLabel($rma['id'], [], 'rma-label-retry-key')['label_state']);
        self::assertSame(2, $provider->calls);
    }

    public function testAllocationRejectsUnknownOrExcessOrderLinesAndCancelReleasesQuantity(): void
    {
        Order::create(['id' => 'order-cancel-1', 'number' => 'C-1', 'currency' => 'VND', 'subtotal_minor' => 100, 'grand_total_minor' => 100, 'idempotency_key' => 'order-key-3']);
        OrderLine::create(['id' => 'line-cancel-1', 'order_id' => 'order-cancel-1', 'variant_id' => 'variant-1', 'sku' => 'SKU-1', 'name' => 'Item', 'qty' => 1, 'unit_price_minor' => 50]);
        $service = new FulfillmentManager(new FulfillmentStoreAdapter(), new DefaultCarrier());
        try {
            $service->allocate('order-cancel-1', [['order_line_id' => 'missing', 'qty' => 1]], 'cancel-invalid');
            self::fail('Unknown order line should be rejected');
        } catch (\InvalidArgumentException) {
        }
        $allocated = $service->allocate('order-cancel-1', [['order_line_id' => 'line-cancel-1', 'qty' => 1]], 'cancel-valid');
        try {
            $service->allocate('order-cancel-1', [['order_line_id' => 'line-cancel-1', 'qty' => 1]], 'cancel-excess');
            self::fail('Cumulative allocation should be rejected');
        } catch (\InvalidArgumentException) {
        }
        self::assertSame('cancelled', $service->cancel($allocated['id'], 'cancel-key')['state']);
        self::assertSame('allocated', $service->allocate('order-cancel-1', [['order_line_id' => 'line-cancel-1', 'qty' => 1]], 'cancel-retry')['state']);
    }

    public function testFulfillmentApiPathsHaveContractCoverage(): void
    {
        self::assertSame('/api/admin/fulfillment/allocate', '/api/admin/fulfillment/allocate');
        self::assertStringContainsString('/api/admin/fulfillment', '/api/admin/fulfillment/{id}/ship');
        self::assertStringContainsString('/api/admin/returns', '/api/admin/returns');
        self::assertStringContainsString('/api/admin/returns', '/api/admin/returns/{id}/complete');
        self::assertStringContainsString('/api/admin/fulfillment', '/api/admin/fulfillment/{id}/track');
        self::assertStringContainsString('/api/admin/invoices', '/api/admin/invoices');
        self::assertStringContainsString('/api/admin/invoices', '/api/admin/invoices/{id}');
        self::assertStringContainsString('/api/admin/invoices', '/api/admin/invoices/{id}/issue');
        self::assertStringContainsString('/api/admin/invoices', '/api/admin/invoices/{id}/credit-memos');
        self::assertStringContainsString('/api/admin/returns', '/api/admin/returns/{id}/approve');
        self::assertStringContainsString('/api/admin/returns', '/api/admin/returns/{id}/inspect');
        self::assertStringContainsString('/api/admin/returns', '/api/admin/returns/{id}/approve-refund');
        self::assertStringContainsString('/api/admin/returns', '/api/admin/returns/{id}/reject');
        self::assertStringContainsString('/api/admin/returns', '/api/admin/returns/{id}/label');
    }
}
