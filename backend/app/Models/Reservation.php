<?php

declare(strict_types=1);

namespace App\Models;

use Siro\Core\Model;

final class Reservation extends Model
{
    protected string $table = 'reservations';

    protected bool $timestamps = false;

    protected array $casts = [
        'qty' => 'int',
        'expires_at' => 'datetime',
    ];

    protected array $fillable = [
        'id',
        'variant_id',
        'source_id',
        'cart_id',
        'order_id',
        'qty',
        'state',
        'expires_at',
        'idempotency_key',
        'allocation_key',
        'store_id',
        'channel_id',
        'locale',
        'currency',
        'company_id',
        'policy',
        'evidence_json',
        'released_at',
    ];
}
