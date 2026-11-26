<?php

declare(strict_types=1);

namespace App\Models;

use Siro\Core\Model;

final class ProductVariant extends Model
{
    protected string $table = 'product_variants';

    protected bool $timestamps = false;

    protected array $casts = [
        'price_minor' => 'int',
        'compare_price_minor' => 'int',
        'weight_g' => 'int',
        'length_mm' => 'int',
        'width_mm' => 'int',
        'height_mm' => 'int',
        'status' => 'string',
    ];

    protected array $fillable = [
        'id',
        'product_id',
        'sku',
        'price_minor',
        'compare_price_minor',
        'tax_class',
        'weight_g',
        'length_mm',
        'width_mm',
        'height_mm',
        'currency',
        'status',
        'created_at',
    ];
}
