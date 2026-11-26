<?php

declare(strict_types=1);

namespace App\Models;

use Siro\Core\Model;

final class GuestOrderAccess extends Model
{
    protected string $table = 'guest_order_access';
    protected bool $timestamps = false;
    protected array $fillable = ['id', 'order_id', 'token_hash', 'expires_at', 'revoked_at'];
}
