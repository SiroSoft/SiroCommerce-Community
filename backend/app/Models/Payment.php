<?php

declare(strict_types=1);

namespace App\Models;

use Siro\Core\Model;

final class Payment extends Model
{
    protected string $table = 'payments';

    protected bool $timestamps = false;

    protected array $casts = [
        'amount_minor' => 'int',
        'minor_unit' => 'int',
        'captured_amount_minor' => 'int',
        'refunded_amount_minor' => 'int',
    ];

    protected array $fillable = [
        'id',
        'order_id',
        'provider',
        'amount_minor',
        'captured_amount_minor',
        'refunded_amount_minor',
        'currency',
        'minor_unit',
        'state',
        'provider_ref',
        'idempotency_key',
        'proof_reference',
        'proof_url',
        'proof_uploaded_at',
    ];
}
