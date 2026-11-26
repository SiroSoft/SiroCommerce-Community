<?php

declare(strict_types=1);

namespace App\Models;

use Siro\Core\Model;

final class ProductAttribute extends Model
{
    protected string $table = 'product_attributes';
    protected bool $timestamps = false;
    protected array $fillable = ['id', 'product_id', 'code', 'value', 'value_type'];
}
