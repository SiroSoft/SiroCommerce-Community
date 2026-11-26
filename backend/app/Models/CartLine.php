<?php

declare(strict_types=1);

namespace App\Models;

use Siro\Core\Model;

final class CartLine extends Model
{
    protected string $table = 'cart_lines';

    protected bool $timestamps = false;

    protected array $casts = [
        'qty' => 'int',
        'unit_price_minor' => 'int',
    ];

    protected array $fillable = [
        'id',
        'cart_id',
        'variant_id',
        'qty',
        'unit_price_minor',
        'slot_start',
        'booking_slot_id',
        'flash_sale_id',
    ];
}
