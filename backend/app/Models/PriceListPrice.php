<?php

declare(strict_types=1);

namespace App\Models;

use Siro\Core\Model;

final class PriceListPrice extends Model
{
    protected string $table = 'pricing_price_list_prices';
    protected bool $timestamps = false;
    protected array $casts = ['unit_price_minor' => 'int'];
    protected array $fillable = ['id', 'price_list_id', 'variant_id', 'unit_price_minor'];
}
