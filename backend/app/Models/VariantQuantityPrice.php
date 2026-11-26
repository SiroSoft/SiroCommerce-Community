<?php

declare(strict_types=1);

namespace App\Models;

use Siro\Core\Model;

final class VariantQuantityPrice extends Model
{
    protected string $table = 'variant_quantity_prices';
    protected bool $timestamps = false;
    protected array $casts = ['minimum_qty' => 'int', 'unit_price_minor' => 'int'];
    protected array $fillable = ['id', 'variant_id', 'minimum_qty', 'unit_price_minor', 'currency'];
}
