<?php

declare(strict_types=1);

namespace App\Models;

use Siro\Core\Model;

final class CurrencyRate extends Model
{
    protected string $table = 'pricing_currency_rates';
    protected bool $timestamps = false;
    protected array $casts = ['rate_numerator' => 'int', 'rate_denominator' => 'int'];
    protected array $fillable = ['id', 'base_currency', 'quote_currency', 'rate_numerator', 'rate_denominator', 'state', 'effective_at'];
}
