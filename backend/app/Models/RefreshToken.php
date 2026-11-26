<?php

declare(strict_types=1);

namespace App\Models;

use Siro\Core\Model;

final class RefreshToken extends Model
{
    protected string $table = 'refresh_tokens';

    protected array $casts = [
        'user_id' => 'int',
        'revoked' => 'int',
        'expires_at' => 'datetime',
    ];

    protected array $fillable = [
        'user_id',
        'jti',
        'revoked',
        'expires_at',
    ];
}
