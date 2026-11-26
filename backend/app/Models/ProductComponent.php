<?php

declare(strict_types=1);

namespace App\Models;

use Siro\Core\Model;

final class ProductComponent extends Model
{
    protected string $table = 'product_components';
    protected bool $timestamps = false;
    protected array $casts = ['qty' => 'int'];
    protected array $fillable = ['id', 'product_id', 'component_variant_id', 'qty', 'substitution_policy', 'substitution_variant_ids'];
}
