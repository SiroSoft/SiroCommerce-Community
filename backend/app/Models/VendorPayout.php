<?php

declare(strict_types=1);

namespace App\Models;

use Siro\Core\Model;

final class VendorPayout extends Model
{
    protected string $table = 'vendor_payouts';

    protected bool $timestamps = false;

    protected array $casts = [
        'amount_minor' => 'int',
        'entries_count' => 'int',
        'created_at' => 'datetime',
        'paid_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    protected array $fillable = [
        'id',
        'vendor_id',
        'amount_minor',
        'currency',
        'state',
        'period_start',
        'period_end',
        'entries_count',
        'idempotency_key',
        'created_at',
        'paid_at',
        'cancelled_at',
    ];
}
