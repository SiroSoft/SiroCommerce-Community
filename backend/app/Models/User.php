<?php

declare(strict_types=1);

namespace App\Models;

use Siro\Core\Model;

final class User extends Model
{
    protected string $table = 'users';

    protected array $casts = [
        'token_version' => 'int',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    protected array $hidden = ['password'];

    protected array $fillable = [
        'name',
        'email',
        'password',
        'role',
        'status',
        'token_version',
        'tenant_ids',
        'seller_ids',
        'site_ids',
        'email_verified_at',
        'birthdate',
    ];
}
