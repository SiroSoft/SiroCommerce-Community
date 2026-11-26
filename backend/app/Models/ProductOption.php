<?php

declare(strict_types=1);

namespace App\Models;

use Siro\Core\Model;

final class ProductOption extends Model
{
    protected string $table = 'product_options';

    protected array $casts = ['sort' => 'int'];

    protected array $fillable = ['id', 'product_id', 'name', 'sort'];
}
