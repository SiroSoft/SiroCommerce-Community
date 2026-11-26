<?php
declare(strict_types=1);

namespace Tests\Integration;

use App\Models\Invoice;
use App\Modules\Platform\Infrastructure\InvoiceStoreAdapter;
use App\Modules\Platform\Infrastructure\PaymentStoreAdapter;
use App\Modules\Platform\Infrastructure\V2SideEffectRepository;
use App\Modules\Webhook\Application\WebhookProcessor;
use App\Repositories\OrderRepository;
use Siro\Core\Event;
use Tests\TestCase;

final class FinanceReconciliationTest extends TestCase
{
    public function testCapturedAndRefundedEventsReconcileTheLinkedInvoice(): void
    {
        (new OrderRepository())->createPayment([
            'id' => 'pay-finance-1', 'order_id' => 'order-finance-1', 'provider' => 'test',
            'amount_minor' => 1000, 'currency' => 'VND', 'state' => 'authorized',
            'provider_ref' => null, 'idempotency_key' => 'finance-auth-1',
        ]);
        (new InvoiceStoreAdapter())->create([
            'id' => 'inv-finance-1', 'number' => 'INV-FINANCE-1', 'order_id' => 'order-finance-1',
            'payment_id' => 'pay-finance-1', 'state' => 'issued', 'currency' => 'VND',
            'amount_minor' => 1000, 'evidence_json' => '{}', 'idempotency_key' => 'finance-invoice-1',
        ]);
        $events = new V2SideEffectRepository();
        (new WebhookProcessor($events, new PaymentStoreAdapter(), new InvoiceStoreAdapter()))->register();

        $capturedBody = json_encode([
            'type' => 'payment.captured', 'payment_id' => 'pay-finance-1', 'provider_ref' => 'provider-finance-1',
        ], JSON_THROW_ON_ERROR);
        $events->receive('test', 'finance-event-1', hash('sha256', $capturedBody), true, $capturedBody);
        Event::emit('webhook.process', ['provider' => 'test', 'event_id' => 'finance-event-1']);
        self::assertSame('paid', Invoice::find('inv-finance-1')?->toArray()['state']);

        $refundedBody = json_encode([
            'type' => 'payment.refunded', 'payment_id' => 'pay-finance-1', 'provider_ref' => 'provider-finance-1',
        ], JSON_THROW_ON_ERROR);
        $events->receive('test', 'finance-event-2', hash('sha256', $refundedBody), true, $refundedBody);
        Event::emit('webhook.process', ['provider' => 'test', 'event_id' => 'finance-event-2']);
        self::assertSame('refunded', Invoice::find('inv-finance-1')?->toArray()['state']);
    }
}
