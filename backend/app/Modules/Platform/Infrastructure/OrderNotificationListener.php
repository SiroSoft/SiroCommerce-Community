<?php

declare(strict_types=1);

namespace App\Modules\Platform\Infrastructure;

use App\Jobs\OrderNotificationJob;
use App\Models\OrderNotificationDelivery;
use App\Models\OutboxMessage;
use App\Support\Arr;
use App\Support\Ids;
use Siro\Core\Database;
use Siro\Core\Event;
use Siro\Core\Logger;
use Siro\Core\Queue;

final class OrderNotificationListener
{
    /** @var array<string, string> */
    private const TEMPLATES = [
        'order.placed' => 'order_placed',
        'payment.captured' => 'payment_captured',
        'payment.failed' => 'payment_failed',
        'fulfillment.shipped' => 'shipment_shipped',
        'fulfillment.delivered' => 'shipment_delivered',
        'fulfillment.ready_for_pickup' => 'pickup_ready',
        'fulfillment.picked_up' => 'pickup_collected',
        'refund.completed' => 'refund_completed',
        'order.refunded' => 'refund_issued',
        'credit_memo.issued' => 'credit_memo_issued',
        'return.resolved' => 'return_resolved',
        'fulfillment.return_completed' => 'rma_completed',
    ];

    public static function templateFor(string $event): ?string
    {
        return self::TEMPLATES[$event] ?? null;
    }

    public function register(): void
    {
        foreach (array_keys(self::TEMPLATES) as $event) {
            Event::on($event, function (mixed $payload) use ($event): void {
                $this->queue($event, $this->map($payload));
            });
        }
    }

    /** @param array<string, mixed> $payload */
    private function queue(string $event, array $payload): void
    {
        $payload = is_array($payload['payload'] ?? null) ? $this->map($payload['payload']) : $this->map($payload);
        $aggregateId = Arr::str($payload, 'order_id');
        if ($aggregateId === '') $aggregateId = Arr::str($payload, 'fulfillment_id', Arr::str($payload, 'return_id'));
        if ($aggregateId === '') return;
        $version = Arr::str($payload, 'event_version', Arr::str($payload, 'event_id', Arr::str($payload, 'idempotency_key', '1')));
        $eventId = hash('sha256', 'order-mail:' . $event . ':' . $aggregateId . ':' . $version);
        $template = self::TEMPLATES[$event];
        $key = hash('sha256', $event . ':' . $aggregateId . ':' . $version);
        $safePayload = ['order_id' => Arr::nullableStr($payload, 'order_id'), 'fulfillment_id' => Arr::nullableStr($payload, 'fulfillment_id'), 'return_id' => Arr::nullableStr($payload, 'return_id'), 'event_version' => $version];
        try {
            if (Database::connection()->inTransaction()) {
                if (OutboxMessage::query()->where('event_id', $eventId)->first() === null) OutboxMessage::create([
                    'id' => Ids::new(), 'event_id' => $eventId, 'topic' => $event,
                    'aggregate_type' => 'order', 'aggregate_id' => $aggregateId,
                    'payload_json' => json_encode($safePayload, JSON_THROW_ON_ERROR), 'state' => 'pending',
                    'attempts' => 0, 'available_at' => date('Y-m-d H:i:s'),
                ]);
                return;
            }
            $created = Database::transaction(function () use ($key, $event, $aggregateId, $template, $safePayload): bool {
                if (OrderNotificationDelivery::query()->where('delivery_key', $key)->first() !== null) return false;
                OrderNotificationDelivery::create([
                    'id' => Ids::new(), 'delivery_key' => $key, 'event_name' => $event,
                    'aggregate_id' => $aggregateId, 'template' => $template, 'state' => 'queued',
                    'attempts' => 0,
                    'provider' => 'native',
                    'redacted_payload_json' => json_encode($safePayload, JSON_THROW_ON_ERROR),
                    'created_at' => date('Y-m-d H:i:s'),
                ]);
                return true;
            });
            if ($created === true) Queue::push(OrderNotificationJob::class, [
                'event_name' => $event, 'aggregate_id' => $aggregateId,
                'template' => $template, 'delivery_key' => $key,
            ], 0, 0, 5);
        } catch (\Throwable $e) {
            // Notifications must never make a successful domain event fail.
            Logger::warning('Order notification was not queued for an event');
        }
    }

    /** @return array<string, mixed> */
    /** @return array<string, mixed> */
    private function map(mixed $payload): array
    {
        /** @var array<string, mixed> $result */
        $result = [];
        if (is_array($payload)) foreach ($payload as $key => $value) if (is_string($key)) $result[$key] = $value;
        return $result;
    }
}
