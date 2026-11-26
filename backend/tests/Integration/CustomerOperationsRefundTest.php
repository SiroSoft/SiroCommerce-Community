<?php
declare(strict_types=1);

namespace Tests\Integration;

use App\Modules\Fulfillment\Application\FulfillmentManager;
use App\Modules\Fulfillment\Application\ReturnManager;
use App\Modules\Payment\Application\PaymentCommandService;
use App\Modules\Payment\Application\PaymentOperationService;
use App\Modules\Payment\Contracts\PaymentProvider;
use App\Modules\Payment\Domain\PaymentOperation;
use App\Modules\Platform\Infrastructure\DefaultCarrier;
use App\Modules\Platform\Infrastructure\FulfillmentStoreAdapter;
use App\Modules\Platform\Infrastructure\InventoryStoreAdapter;
use App\Modules\Platform\Infrastructure\PaymentStoreAdapter;
use App\Modules\Platform\Infrastructure\ReturnStoreAdapter;
use App\Modules\Platform\Infrastructure\V2SideEffectRepository;
use App\Models\Order;
use App\Models\OrderLine;
use App\Repositories\InventoryRepository;
use App\Repositories\OrderRepository;
use Tests\TestCase;

final class CustomerOperationsRefundTest extends TestCase
{
    public function testApprovedReturnRefundsCapturedPaymentOnce(): void
    {
        [$returns, $rma] = $this->returnManager(new class implements PaymentProvider {
            public function execute(PaymentOperation $operation, array $context): array { return ['state' => 'succeeded', 'provider_ref' => 'test-refund']; }
            public function verifyWebhook(string $rawBody, array $headers): bool { return true; }
        }, 'customer-refund-success');

        self::assertSame('approved_refund', $returns->approveRefund($rma['id'], 'refund-1')['state']);
        self::assertSame(100, (new V2SideEffectRepository())->refundedAmount('payment-customer-refund-success'));
        self::assertSame('approved_refund', $returns->approveRefund($rma['id'], 'refund-duplicate')['state']);
        self::assertSame(1, \App\Models\PaymentOperation::query()->where('type', 'refund')->count());
    }

    public function testFailedRefundCanBeRetriedWithANewCommandKey(): void
    {
        $provider = new class implements PaymentProvider {
            public int $calls = 0;
            public function execute(PaymentOperation $operation, array $context): array { $this->calls++; return $this->calls === 1 ? ['state' => 'failed', 'error' => 'declined'] : ['state' => 'succeeded', 'provider_ref' => 'retry-refund']; }
            public function verifyWebhook(string $rawBody, array $headers): bool { return true; }
        };
        [$returns, $rma] = $this->returnManager($provider, 'customer-refund-retry');
        try { $returns->approveRefund($rma['id'], 'refund-failed'); self::fail('The failed provider result should be surfaced'); } catch (\RuntimeException $e) { self::assertSame('declined', $e->getMessage()); }
        self::assertSame('failed', (new ReturnStoreAdapter())->find($rma['id'])['refund_state']);
        self::assertSame('approved_refund', $returns->approveRefund($rma['id'], 'refund-retry')['state']);
        self::assertSame(2, $provider->calls);
    }

    /** @return array{0: ReturnManager, 1: array<string,mixed>} */
    private function returnManager(PaymentProvider $provider, string $suffix): array
    {
        $orderId = 'order-' . $suffix;
        $lineId = 'line-' . $suffix;
        $paymentId = 'payment-' . $suffix;
        Order::create(['id' => $orderId, 'number' => 'N-' . $suffix, 'currency' => 'VND', 'subtotal_minor' => 100, 'grand_total_minor' => 100, 'idempotency_key' => 'order-key-' . $suffix]);
        OrderLine::create(['id' => $lineId, 'order_id' => $orderId, 'variant_id' => 'variant-' . $suffix, 'sku' => 'SKU', 'name' => 'Item', 'qty' => 1, 'unit_price_minor' => 100]);
        (new OrderRepository())->createPayment(['id' => $paymentId, 'order_id' => $orderId, 'provider' => 'test', 'amount_minor' => 100, 'captured_amount_minor' => 100, 'currency' => 'VND', 'state' => 'captured', 'idempotency_key' => 'payment-key-' . $suffix]);
        $fulfillment = new FulfillmentManager(new FulfillmentStoreAdapter(), new DefaultCarrier());
        $allocated = $fulfillment->allocate($orderId, [['order_line_id' => $lineId, 'qty' => 1]], 'fulfillment-' . $suffix);
        $fulfillment->ship($allocated['id'], 'ship-' . $suffix);
        $fulfillment->deliver($allocated['id'], 'deliver-' . $suffix);
        $payments = new PaymentCommandService(new PaymentStoreAdapter(), new PaymentOperationService(new V2SideEffectRepository()), $provider);
        $returns = new ReturnManager(new ReturnStoreAdapter(), new FulfillmentStoreAdapter(), new InventoryStoreAdapter(new InventoryRepository()), null, null, $payments);
        $rma = $returns->request($orderId, $allocated['id'], [['order_line_id' => $lineId, 'qty' => 1]], 'defective', 'return-' . $suffix);
        $returns->approve($rma['id'], 'approve-' . $suffix);
        $returns->inspect($rma['id'], 'inspect-' . $suffix, 'restock');
        return [$returns, $rma];
    }
}
