<?php

declare(strict_types=1);

namespace App\Models;

use Siro\Core\Model;

final class ShippingShipment extends Model
{
    protected string $table = 'shipping_shipments';
    protected array $fillable = ['id', 'provider', 'provider_reference', 'tracking_number', 'order_id', 'fulfillment_id', 'state', 'idempotency_key', 'payload_json', 'packages_json', 'last_error', 'attempts'];
}
