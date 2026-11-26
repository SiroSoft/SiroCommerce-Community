<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Jobs\OrderNotificationJob;
use App\Mails\OrderNotificationMail;
use App\Models\Customer;
use App\Models\CustomerNotificationPreference;
use App\Models\Order;
use App\Models\OrderNotificationDelivery;
use App\Modules\Platform\Infrastructure\OrderNotificationListener;
use App\Modules\Platform\Infrastructure\OutboxDispatcher;
use App\Support\Ids;
use Siro\Core\Database;
use Siro\Core\Event;
use Siro\Core\Mail;
use Siro\Core\Queue;
use Tests\TestCase;
use App\Contracts\TransactionalMessageProviderInterface;

final class OrderNotificationWorkflowTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Event::flush();
        Queue::fake();
        Mail::fake();
    }

    protected function tearDown(): void
    {
        Mail::reset();
        Queue::reset();
        Event::flush();
        parent::tearDown();
    }

    public function testEventMapsToOneSafeQueuedDeliveryAndSuppressesDuplicates(): void
    {
        $order = $this->order('customer@example.test');
        (new OrderNotificationListener())->register();

        Event::emit('payment.captured', [
            'order_id' => $order['id'],
            'event_id' => 'payment-event-1',
            'payment_token' => 'must-not-be-persisted',
        ]);
        Event::emit('payment.captured', [
            'order_id' => $order['id'],
            'event_id' => 'payment-event-1',
            'payment_token' => 'must-not-be-persisted',
        ]);

        $this->assertCount(1, Queue::getFakedJobs());
        $job = Queue::getFakedJobs()[0]['data'];
        $this->assertSame('payment.captured', $job['event_name']);
        $this->assertArrayNotHasKey('payment_token', $job);
        $this->assertSame(1, OrderNotificationDelivery::query()->count());
    }

    public function testTransactionalEventWaitsForOutboxPublication(): void
    {
        $order = $this->order('customer@example.test');
        (new OrderNotificationListener())->register();
        Database::transaction(function () use ($order): void {
            Event::emit('order.placed', ['order_id' => $order['id'], 'event_version' => 'v1']);
            $this->assertCount(0, Queue::getFakedJobs());
        });
        $this->assertSame(1, \App\Models\OutboxMessage::query()->count());
        (new OutboxDispatcher())->dispatch(10, true);
        $this->assertCount(1, Queue::getFakedJobs());
    }

    public function testCustomerAndGuestRecipientsAndInvalidEmailAreHandled(): void
    {
        $customerOrder = $this->order('customer@example.test');
        $customerDelivery = $this->delivery($customerOrder, 'customer-key');
        (new OrderNotificationJob())->handle($this->jobData($customerOrder, 'customer-key'));
        Mail::assertSentTo('customer@example.test');
        $this->assertSame('sent', OrderNotificationDelivery::query()->where('delivery_key', 'customer-key')->first()->toArray()['state']);

        $guestOrder = $this->order(null, 'guest@example.test');
        $guestDelivery = $this->delivery($guestOrder, 'guest-key');
        (new OrderNotificationJob())->handle($this->jobData($guestOrder, 'guest-key'));
        Mail::assertSentTo('guest@example.test');
        $this->assertSame('sent', OrderNotificationDelivery::query()->where('delivery_key', 'guest-key')->first()->toArray()['state']);

        $invalidOrder = $this->order(null, 'not-an-email');
        $invalidDelivery = $this->delivery($invalidOrder, 'invalid-key');
        (new OrderNotificationJob())->handle($this->jobData($invalidOrder, 'invalid-key'));
        $this->assertSame('skipped', OrderNotificationDelivery::query()->where('delivery_key', 'invalid-key')->first()->toArray()['state']);
    }

    public function testTemplatesEscapeOrderNumberAndCoverRequiredEvents(): void
    {
        $mail = new OrderNotificationMail();
        foreach (['order_placed', 'payment_captured', 'payment_failed', 'shipment_shipped', 'shipment_delivered', 'refund_completed', 'return_resolved'] as $template) {
            $body = $mail->build($template, ['number' => '<order>']);
            $this->assertStringContainsString('&lt;order&gt;', $body);
            $this->assertStringNotContainsString('guest_access_token', $body);
        }
    }

    public function testMarketingOptOutDoesNotSuppressTransactionalDelivery(): void
    {
        $order = $this->order('transactional@example.test');
        CustomerNotificationPreference::create(['customer_id' => $this->customerIdForOrder($order['id']), 'transactional_email' => 1, 'marketing_email' => 0]);
        $this->delivery($order, 'consent-separation');

        (new OrderNotificationJob())->handle($this->jobData($order, 'consent-separation'));

        Mail::assertSentTo('transactional@example.test');
        $this->assertSame('sent', OrderNotificationDelivery::query()->where('delivery_key', 'consent-separation')->first()->toArray()['state']);
    }

    public function testProviderFailureUsesRedactedErrorAndDeadLettersAfterRetries(): void
    {
        $order = $this->order('failure@example.test');
        $delivery = $this->delivery($order, 'provider-failure');
        $provider = new class implements TransactionalMessageProviderInterface {
            public function send(string $recipient, string $subject, string $html): void { throw new \RuntimeException('secret recipient and provider response'); }
        };

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            try {
                (new OrderNotificationJob($provider))->handle($this->jobData($order, 'provider-failure'));
            } catch (\Throwable) {
            }
            if ($attempt < 5) {
                $delivery = OrderNotificationDelivery::query()->where('delivery_key', 'provider-failure')->first();
                $delivery->setAttribute('next_attempt_at', null);
                $delivery->save();
            }
        }

        $state = OrderNotificationDelivery::query()->where('delivery_key', 'provider-failure')->first()->toArray();
        $this->assertSame('dead', $state['state']);
        $this->assertSame(5, (int) $state['attempts']);
        $this->assertStringContainsString('provider unavailable', (string) $state['last_error']);
        $this->assertStringNotContainsString('secret recipient', (string) $state['last_error']);
    }

    public function testUnknownEventIsNotMapped(): void
    {
        $order = $this->order('unknown@example.test');
        (new OrderNotificationListener())->register();
        Event::emit('payment.authorized', ['order_id' => $order['id'], 'token' => 'secret']);
        $this->assertCount(0, Queue::getFakedJobs());
        $this->assertNull(OrderNotificationListener::templateFor('payment.authorized'));
    }

    /** @return array<string, mixed> */
    private function order(?string $email, ?string $guestEmail = null): array
    {
        $customerId = $email === null ? null : Ids::new();
        if ($customerId !== null) Customer::create(['id' => $customerId, 'email' => $email, 'name' => 'Test']);
        $id = Ids::new();
        Order::create(['id' => $id, 'number' => 'ORD-' . substr($id, -8), 'customer_id' => $customerId, 'guest_email' => $guestEmail, 'currency' => 'VND', 'subtotal_minor' => 100, 'grand_total_minor' => 100, 'state' => 'pending', 'idempotency_key' => Ids::new()]);
        return ['id' => $id, 'number' => 'ORD-' . substr($id, -8)];
    }

    private function customerIdForOrder(string $orderId): string
    {
        return (string) Order::find($orderId)->toArray()['customer_id'];
    }

    /** @param array<string, mixed> $order */
    private function delivery(array $order, string $key): OrderNotificationDelivery
    {
        return OrderNotificationDelivery::create(['id' => Ids::new(), 'delivery_key' => $key, 'event_name' => 'order.placed', 'aggregate_id' => $order['id'], 'template' => 'order_placed', 'state' => 'queued', 'attempts' => 0, 'created_at' => date('Y-m-d H:i:s')]);
    }

    /** @param array<string, mixed> $order */
    /** @return array<string, mixed> */
    private function jobData(array $order, string $key): array
    {
        return ['event_name' => 'order.placed', 'aggregate_id' => $order['id'], 'template' => 'order_placed', 'delivery_key' => $key];
    }
}
