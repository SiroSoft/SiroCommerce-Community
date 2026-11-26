<?php

declare(strict_types=1);

namespace App\Models;

use Siro\Core\Model;

final class FulfillmentOrder extends Model
{
    protected string $table = 'fulfillment_orders';
    protected bool $timestamps = false;
    protected array $fillable = ['id', 'order_id', 'state', 'idempotency_key', 'created_at', 'delivered_at', 'store_id', 'channel_id', 'company_id', 'allocation_evidence_json', 'backorder_qty', 'pickup_point_code', 'ready_at', 'ready_by', 'picked_up_at'];
}
