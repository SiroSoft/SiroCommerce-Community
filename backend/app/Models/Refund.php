<?php

declare(strict_types=1);

namespace App\Models;

use Siro\Core\Model;

final class Refund extends Model
{
    protected string $table = 'refunds';

    protected bool $timestamps = false;

    protected array $casts = [
        'amount_minor' => 'int',
        'minor_unit' => 'int',
    ];

    protected array $fillable = [
        'id',
        'order_id',
        'payment_id',
        'amount_minor',
        'currency',
        'minor_unit',
        'tax_minor',
        'evidence_json',
        'reason',
        'state',
        'idempotency_key',
    ];
}
