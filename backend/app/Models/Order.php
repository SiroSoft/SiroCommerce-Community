<?php

declare(strict_types=1);

namespace App\Models;

use Siro\Core\Model;

/**
 * Order aggregate root — totals are immutable snapshots (spec 02§3.1).
 *
 * @property string $id
 * @property string $number
 * @property string $state
 * @property int $grand_total_minor
 */
final class Order extends Model
{
    protected string $table = 'orders';

    protected bool $timestamps = false;

    protected array $casts = [
        'subtotal_minor' => 'int',
        'discount_minor' => 'int',
        'shipping_minor' => 'int',
        'tax_minor' => 'int',
        'grand_total_minor' => 'int',
        'minor_unit' => 'int',
        'tax_inclusive' => 'bool',
        'created_at' => 'datetime',
    ];

    protected array $fillable = [
        'id',
        'number',
        'customer_id',
        'guest_email',
        'guest_access_token',
        'currency',
        'minor_unit',
        'tax_inclusive',
        'tax_evidence_json',
        'promotion_evidence_json',
        'promotion_rule_json',
        'subtotal_minor',
        'discount_minor',
        'shipping_minor',
        'tax_minor',
        'grand_total_minor',
        'state',
        'idempotency_key',
        'store_id',
        'channel_id',
        'tenant_id',
        'seller_id',
        'site_id',
        'pickup_point_code',
    ];
}
