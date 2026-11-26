<?php

declare(strict_types=1);

namespace App\Models;

use Siro\Core\Model;

final class CustomerGroupPrice extends Model
{
    protected string $table = 'pricing_customer_group_prices';
    protected bool $timestamps = false;
    protected array $casts = ['unit_price_minor' => 'int'];
    protected array $fillable = ['id', 'customer_group_id', 'variant_id', 'currency', 'unit_price_minor'];
}
