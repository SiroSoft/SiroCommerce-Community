<?php

declare(strict_types=1);

namespace App\Models;

use Siro\Core\Model;

final class OrderTimelineEvent extends Model
{
    protected string $table = 'order_timeline_events';
    protected bool $timestamps = false;
    protected array $fillable = ['id', 'order_id', 'event_type', 'state', 'actor', 'payload_json', 'store_id', 'channel_id', 'created_at'];
}
