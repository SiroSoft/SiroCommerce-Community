<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Models\CreditMemo;
use App\Models\Invoice;
use App\Models\InventoryItem;
use App\Models\ReturnEvent;
use App\Models\ReturnInventoryPosting;
use App\Models\StockLedger;
use App\Modules\Cart\Application\CartService;
use App\Modules\Checkout\Application\CheckoutService;
use App\Modules\Checkout\Application\QuoteService;
use App\Modules\Finance\Application\CreditMemoManager;
use App\Modules\Finance\Application\InvoiceManager;
use App\Modules\Fulfillment\Application\FulfillmentManager;
use App\Modules\Fulfillment\Application\ReturnManager;
use App\Modules\Inventory\Application\InventoryManager;
use App\Modules\Payment\Application\PaymentCommandService;
use App\Modules\Platform\Infrastructure\FulfillmentStoreAdapter;
use App\Modules\Platform\Infrastructure\InventoryStoreAdapter;
use App\Modules\Platform\Infrastructure\ReturnStoreAdapter;
use App\Providers\CommerceServiceProvider;
use App\Repositories\CartRepository;
use App\Repositories\InventoryRepository;
use App\Repositories\OrderRepository;
use Siro\Core\Container;
use Tests\TestCase;

final class CommerceWorkflowTest extends TestCase
{
    public function testCatalogToCreditMemoAndReturnWorkflowIsReplaySafe(): void
    {
        (new CommerceServiceProvider())->register();
        $container = Container::getInstance();
        $catalog = new CartRepository();
        $inventory = new InventoryRepository();
        $variant = $catalog->createVariant([
            'id' => 'workflow-variant-1',
            'product_id' => 'workflow-product-1',
            'sku' => 'WORKFLOW-001',
            'price_minor' => 1200,
        ]);
        $inventory->setOnHand($variant['id'], 3, 'workflow-fixture');

        /** @var CartService $carts */
        $carts = $container->make(CartService::class);
        /** @var CheckoutService $checkout */
        $checkout = $container->make(CheckoutService::class);
        /** @var QuoteService $quotes */
        $quotes = $container->make(QuoteService::class);
        $cart = $carts->open(null, 'VND');
        $carts->addLine($cart['id'], $variant['id'], 2);
        $quote = $quotes->quote($cart['id']);
        self::assertSame(2400, $quote['grand_total_minor']);
        self::assertNotEmpty($quote['lines']);

        $order = $checkout->place($cart['id'], 'workflow-place-1', 'workflow@example.test');
        self::assertSame('awaiting_payment', $order['state']);
        self::assertSame('awaiting_payment', (new OrderRepository())->transitions($order['id'])[0]['to_state']);
        self::assertSame(1, (new InventoryManager(new InventoryStoreAdapter($inventory)))->available($variant['id']));

        $payment = (new OrderRepository())->payments($order['id'])[0];
        /** @var PaymentCommandService $payments */
        $payments = $container->make(PaymentCommandService::class);
        $captured = $payments->execute($payment['id'], 'capture', 2400, 'VND', 'workflow-capture-1', ['order_id' => $order['id']]);
        self::assertSame('captured', $captured['state']);
        self::assertSame($captured, $payments->execute($payment['id'], 'capture', 2400, 'VND', 'workflow-capture-1', ['order_id' => $order['id']]));

        /** @var InvoiceManager $invoices */
        $invoices = $container->make(InvoiceManager::class);
        $invoice = $invoices->create($order['id'], $payment['id'], 'workflow-invoice-1');
        self::assertSame('draft', $invoice['state']);
        $issued = $invoices->issue($invoice['id'], 'workflow-invoice-issue-1');
        self::assertSame('issued', $issued['state']);

        /** @var FulfillmentManager $fulfillment */
        $fulfillment = $container->make(FulfillmentManager::class);
        $line = (new OrderRepository())->lines($order['id'])[0];
        $allocation = $fulfillment->allocate($order['id'], [['order_line_id' => $line['id'], 'qty' => 2]], 'workflow-allocate-1');
        $fulfillment->ship($allocation['id'], 'workflow-ship-1');
        self::assertSame('delivered', $fulfillment->deliver($allocation['id'], 'workflow-deliver-1')['state']);

        /** @var ReturnManager $returns */
        $returns = $container->make(ReturnManager::class);
        $rma = $returns->request($order['id'], $allocation['id'], [['order_line_id' => $line['id'], 'qty' => 1]], 'damaged', 'workflow-return-1');
        $returns->approve($rma['id'], 'workflow-return-approve-1');
        $returns->inspect($rma['id'], 'workflow-return-inspect-1', 'restock');
        $returns->approveRefund($rma['id'], 'workflow-return-refund-1');
        $completed = $returns->complete($rma['id'], 'workflow-return-complete-1');
        self::assertSame('completed', $completed['state']);
        self::assertSame($completed, $returns->complete($rma['id'], 'workflow-return-complete-retry'));
        self::assertSame(2, (new InventoryManager(new InventoryStoreAdapter($inventory)))->available($variant['id']));
        self::assertSame(1, ReturnInventoryPosting::query()->where('return_id', $rma['id'])->count());
        self::assertSame(4, ReturnEvent::query()->where('return_id', $rma['id'])->count());

        /** @var CreditMemoManager $creditMemos */
        $creditMemos = $container->make(CreditMemoManager::class);
        $memo = $creditMemos->create($invoice['id'], 1200, 'workflow return', 'workflow-credit-memo-1');
        self::assertSame('issued', $memo['state']);
        $replayedMemo = $creditMemos->create($invoice['id'], 1200, 'different reason', 'workflow-credit-memo-1');
        self::assertSame($memo['id'], $replayedMemo['id']);
        self::assertSame('issued', $replayedMemo['state']);
        self::assertSame(2400, (new OrderRepository())->payments($order['id'])[0]['captured_amount_minor']);
        self::assertSame(1200, (new OrderRepository())->payments($order['id'])[0]['refunded_amount_minor']);
        self::assertSame(1200, (new \App\Modules\Platform\Infrastructure\V2SideEffectRepository())->refundedAmount($payment['id']));
        self::assertSame('partially_refunded', (new OrderRepository())->payments($order['id'])[0]['state']);
        self::assertSame(1, CreditMemo::query()->where('invoice_id', $invoice['id'])->count());
        self::assertSame(1, StockLedger::query()->where('variant_id', $variant['id'])->where('reason', 'rma_restock')->count());
        self::assertNotEmpty((string) Invoice::find($invoice['id'])?->toArray()['evidence_json']);
        self::assertSame(1, InventoryItem::query()->where('variant_id', $variant['id'])->count());
    }
}
