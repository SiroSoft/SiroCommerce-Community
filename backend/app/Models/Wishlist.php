<?php

declare(strict_types=1);

namespace App\Models;

use Siro\Core\Model;

final class Wishlist extends Model
{
    protected string $table = 'wishlists';
    protected bool $timestamps = false;
    protected array $fillable = ['id', 'customer_id', 'product_id', 'variant_id', 'created_at'];
}
