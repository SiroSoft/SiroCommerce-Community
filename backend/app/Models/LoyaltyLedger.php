<?php

declare(strict_types=1);

namespace App\Models;

use Siro\Core\Model;

final class LoyaltyLedger extends Model
{
    protected string $table = 'loyalty_ledger';
    protected bool $timestamps = false;
    protected array $casts = ['points' => 'int'];
    protected array $fillable = ['id', 'account_id', 'customer_id', 'operation', 'points', 'idempotency_key', 'request_fingerprint', 'order_id', 'expires_at', 'expired_at', 'created_at'];
}
