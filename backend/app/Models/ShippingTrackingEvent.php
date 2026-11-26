<?php

declare(strict_types=1);

namespace App\Models;

use Siro\Core\Model;

final class ShippingTrackingEvent extends Model
{
    protected string $table = 'shipping_tracking_events';
    protected array $fillable = ['id', 'shipment_id', 'provider', 'event_id', 'provider_status', 'state', 'payload_json', 'headers_json'];
}
