<?php
declare(strict_types=1);
namespace App\Modules\Customer\Domain;

use App\Modules\Shared\Domain\EventEnvelope;

final class CustomerNotificationEvent
{
    /** @param array<string, mixed> $payload */
    public static function create(string $eventId, string $customerId, string $template, string $correlationId, string $causationId, array $payload = []): EventEnvelope
    {
        return new EventEnvelope($eventId, 'customer.notification.requested.v1', date(DATE_ATOM), $correlationId, $causationId, 'system', ['customer_id' => $customerId, 'template' => $template] + $payload);
    }
}
