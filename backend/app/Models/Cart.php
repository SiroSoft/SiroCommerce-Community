<?php

declare(strict_types=1);

namespace App\Models;

use Siro\Core\Model;

final class Cart extends Model
{
    protected string $table = 'carts';

    protected array $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    protected array $fillable = [
        'id',
        'customer_id',
        'access_token_hash',
        'currency',
        'state',
        'expires_at',
    ];
}
