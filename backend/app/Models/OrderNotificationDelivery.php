<?php

declare(strict_types=1);

namespace App\Models;

use Siro\Core\Model;

final class OrderNotificationDelivery extends Model
{
    protected string $table = 'order_notification_deliveries';
    protected bool $timestamps = false;
    protected array $fillable = ['id', 'delivery_key', 'event_name', 'aggregate_id', 'template', 'state', 'attempts', 'locked_at', 'last_error', 'sent_at', 'created_at', 'provider', 'next_attempt_at', 'dead_lettered_at', 'replay_count', 'redacted_payload_json'];
}
