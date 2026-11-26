<?php

declare(strict_types=1);

namespace App\Models;

use Siro\Core\Model;

final class ProductOptionValue extends Model
{
    protected string $table = 'product_option_values';

    protected array $casts = ['sort' => 'int', 'surcharge_minor' => 'int'];

    protected array $fillable = ['id', 'option_id', 'value', 'sort', 'surcharge_minor', 'currency'];
}
