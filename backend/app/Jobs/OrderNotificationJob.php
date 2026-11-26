<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Mails\OrderNotificationMail;
use App\Models\Customer;
use App\Contracts\TransactionalMessageProviderInterface;
use App\Models\FulfillmentOrder;
use App\Models\Order;
use App\Models\OrderNotificationDelivery;
use App\Models\ReturnRequest;
use App\Modules\Platform\Infrastructure\OrderNotificationListener;
use App\Support\Arr;
use Siro\Core\QueueInterface;

/** Resolves customer data at execution time and queues the native mail job. */
final class OrderNotificationJob implements QueueInterface
{
    public int $maxAttempts = 5;

    public function __construct(private readonly ?TransactionalMessageProviderInterface $provider = null)
    {
    }

    /** @param array<string, mixed> $data */
    public function handle(array $data = []): void
    {
        $event = Arr::str($data, 'event_name');
        $aggregateId = Arr::str($data, 'aggregate_id');
        $template = Arr::str($data, 'template');
        $deliveryKey = Arr::str($data, 'delivery_key');
        if ($event === '' || $aggregateId === '' || $template === '' || $deliveryKey === '') return;

        /** @var OrderNotificationDelivery|null $delivery */
        $delivery = OrderNotificationDelivery::query()->where('delivery_key', $deliveryKey)->first();
        if ($delivery === null || in_array(Arr::str($delivery->toArray(), 'state'), ['sent', 'dead', 'skipped'], true) || !$this->claim($delivery)) return;

        if (!OrderNotificationListener::templateFor($event) || OrderNotificationListener::templateFor($event) !== $template) {
            $this->mark($delivery, 'dead', 'Unsupported event/template mapping');
            return;
        }

        $order = $this->order($event, $aggregateId);
        if ($order === null) {
            $this->mark($delivery, 'skipped');
            return;
        }
        $recipient = $this->recipient($order);
        if ($recipient === null) {
            $this->mark($delivery, 'skipped');
            return;
        }

        $view = ['number' => Arr::str($order, 'number'), 'state' => Arr::str($order, 'state')];
        $mail = new OrderNotificationMail();
        try {
            ($this->provider ?? new \App\Modules\Platform\Infrastructure\NativeTransactionalMessageProvider())->send($recipient, $mail->subject($template, $view), $mail->build($template, $view));
        } catch (\Throwable $e) {
            $this->fail($delivery, $e);
            throw $e;
        }
        $this->mark($delivery, 'sent');
    }

    /** @return array<string, mixed>|null */
    private function order(string $event, string $aggregateId): ?array
    {
        if (in_array($event, ['fulfillment.shipped', 'fulfillment.delivered'], true)) {
            $fulfillment = FulfillmentOrder::find($aggregateId);
            $aggregateId = $fulfillment === null ? '' : Arr::str($fulfillment->toArray(), 'order_id');
        } elseif ($event === 'return.resolved' || str_starts_with($event, 'fulfillment.return_')) {
            $return = ReturnRequest::find($aggregateId);
            $aggregateId = $return === null ? '' : Arr::str($return->toArray(), 'order_id');
        }
        if ($aggregateId === '') return null;
        $order = Order::find($aggregateId);
        return $order === null ? null : Arr::row($order);
    }

    /** @param array<string, mixed> $order */
    private function recipient(array $order): ?string
    {
        $email = Arr::nullableStr($order, 'guest_email');
        $customerId = Arr::nullableStr($order, 'customer_id');
        if ($email === null && $customerId !== null) {
            $customer = Customer::find($customerId);
            $email = $customer === null ? null : Arr::nullableStr(Arr::row($customer), 'email');
        }
        return $email !== null && filter_var($email, FILTER_VALIDATE_EMAIL) !== false ? $email : null;
    }

    private function mark(OrderNotificationDelivery $delivery, string $state, ?string $error = null): void
    {
        $key = Arr::str($delivery->toArray(), 'delivery_key');
        $fresh = $key === '' ? null : OrderNotificationDelivery::query()->where('delivery_key', $key)->first();
        if ($fresh !== null) $delivery = $fresh;
        $delivery->setAttribute('state', $state);
        if ($state === 'sent') $delivery->setAttribute('sent_at', date('Y-m-d H:i:s'));
        if ($error !== null) $delivery->setAttribute('last_error', $error);
        $delivery->save();
    }

    private function claim(OrderNotificationDelivery $delivery): bool
    {
        $claimed = \Siro\Core\Database::transaction(function () use ($delivery): bool {
            /** @var OrderNotificationDelivery|null $row */
            $row = OrderNotificationDelivery::query()->where('delivery_key', Arr::str($delivery->toArray(), 'delivery_key'))->lockForUpdate()->first();
            if ($row === null || in_array(Arr::str($row->toArray(), 'state'), ['sent', 'dead', 'skipped'], true)) return false;
            $lockedAt = Arr::nullableStr($row->toArray(), 'locked_at');
            if (Arr::str($row->toArray(), 'state') === 'sending' && $lockedAt !== null && strtotime($lockedAt) > time() - 900) return false;
            $row->setAttribute('state', 'sending');
            $row->setAttribute('locked_at', date('Y-m-d H:i:s'));
            $row->setAttribute('attempts', Arr::int($row->toArray(), 'attempts') + 1);
            $row->save();
            return true;
        });
        return $claimed === true;
    }

    private function fail(OrderNotificationDelivery $delivery, \Throwable $error): void
    {
        $key = Arr::str($delivery->toArray(), 'delivery_key');
        $fresh = $key === '' ? null : OrderNotificationDelivery::query()->where('delivery_key', $key)->first();
        if ($fresh !== null) $delivery = $fresh;
        $attempts = Arr::int($delivery->toArray(), 'attempts');
        $dead = $attempts >= $this->maxAttempts;
        $delivery->setAttribute('state', $dead ? 'dead' : 'failed');
        $delivery->setAttribute('locked_at', null);
        $delivery->setAttribute('last_error', substr(get_class($error) . ': provider unavailable', 0, 1000));
        $delivery->setAttribute('next_attempt_at', $dead ? null : date('Y-m-d H:i:s', time() + min(3600, 2 ** min($attempts, 10))));
        if ($dead) $delivery->setAttribute('dead_lettered_at', date('Y-m-d H:i:s'));
        $delivery->save();
    }
}
