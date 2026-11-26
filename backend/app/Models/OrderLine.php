<?php

declare(strict_types=1);

namespace App\Models;

use Siro\Core\Model;

final class OrderLine extends Model
{
    protected string $table = 'order_lines';

    protected bool $timestamps = false;

    protected array $casts = [
        'qty' => 'int',
        'unit_price_minor' => 'int',
        'vendor_commission_minor' => 'int',
    ];

    protected array $fillable = [
        'id',
        'order_id',
        'variant_id',
        'sku',
        'name',
        'qty',
        'unit_price_minor',
        'product_type',
        'components_json',
        'vendor_id',
        'vendor_commission_minor',
    ];
}
