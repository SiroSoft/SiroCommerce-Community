<?php

declare(strict_types=1);

namespace App\Models;

use Siro\Core\Model;

final class CouponUsage extends Model
{
    protected string $table = 'coupon_usages';

    protected bool $timestamps = false;

    protected array $casts = [
        'discount_minor' => 'int',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    protected array $fillable = [
        'id',
        'coupon_id',
        'order_id',
        'discount_minor',
        'created_at',
        'reservation_key', 'state', 'updated_at',
    ];
}
