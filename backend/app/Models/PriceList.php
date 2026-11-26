<?php

declare(strict_types=1);

namespace App\Models;

use Siro\Core\Model;

final class PriceList extends Model
{
    protected string $table = 'pricing_price_lists';
    protected bool $timestamps = false;
    protected array $casts = ['priority' => 'int'];
    protected array $fillable = ['id', 'code', 'name', 'currency', 'customer_group_id', 'priority', 'state'];
}
