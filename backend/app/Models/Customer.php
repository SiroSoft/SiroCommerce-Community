<?php

declare(strict_types=1);

namespace App\Models;

use Siro\Core\Model;

final class Customer extends Model
{
    protected string $table = 'customers';

    protected bool $timestamps = false;

    protected array $casts = [
        'created_at' => 'datetime',
    ];

    protected array $fillable = [
        'id',
        'email',
        'name',
        'created_at',
    ];
}
