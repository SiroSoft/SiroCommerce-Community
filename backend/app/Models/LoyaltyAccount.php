<?php

declare(strict_types=1);

namespace App\Models;

use Siro\Core\Model;

final class LoyaltyAccount extends Model
{
    protected string $table = 'loyalty_accounts';
    protected bool $timestamps = false;
    protected array $casts = ['balance_points' => 'int'];
    protected array $fillable = ['id', 'customer_id', 'balance_points', 'state', 'created_at', 'updated_at'];
}
