<?php

declare(strict_types=1);

namespace App\Models;

use Siro\Core\Model;

final class VariantDependency extends Model
{
    protected string $table = 'variant_dependencies';
    protected bool $timestamps = false;
    protected array $fillable = ['id', 'product_id', 'option_value_id', 'depends_on_option_value_id', 'operator'];
}
