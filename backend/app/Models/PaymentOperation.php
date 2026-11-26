<?php

declare(strict_types=1);

namespace App\Models;

use Siro\Core\Model;

final class PaymentOperation extends Model
{
    protected string $table = 'payment_operations';
    protected bool $timestamps = false;
    protected array $casts = ['amount_minor' => 'int'];
    protected array $fillable = ['id', 'payment_id', 'type', 'amount_minor', 'currency', 'idempotency_key', 'state', 'provider_ref', 'request_hash', 'result_json', 'last_error', 'created_at', 'completed_at'];
}
