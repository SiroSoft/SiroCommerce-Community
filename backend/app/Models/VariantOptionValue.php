<?php

declare(strict_types=1);

namespace App\Models;

use Siro\Core\Model;

final class VariantOptionValue extends Model
{
    protected string $table = 'variant_option_values';
    protected bool $timestamps = false;
    protected array $fillable = ['variant_id', 'option_id', 'option_value_id'];
}
