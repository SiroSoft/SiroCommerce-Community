<?php
declare(strict_types=1);
namespace App\Modules\Order\Domain;

use App\Modules\Shared\Domain\EventEnvelope;

final class OrderAuditEvent
{
    /** @param array<string, mixed> $payload */
    public static function create(string $eventId, string $orderId, string $actor, string $correlationId, string $causationId, array $payload): EventEnvelope
    {
        return new EventEnvelope($eventId, 'order.audit.v1', date(DATE_ATOM), $correlationId, $causationId, $actor, ['order_id' => $orderId] + $payload);
    }
}
