<?php

declare(strict_types=1);

namespace App\Models;

use Siro\Core\Model;

final class VendorLedgerEntry extends Model
{
    protected string $table = 'vendor_ledger';

    protected bool $timestamps = false;

    protected array $casts = [
        'gross_minor' => 'int',
        'commission_minor' => 'int',
        'net_minor' => 'int',
        'created_at' => 'datetime',
    ];

    protected array $fillable = [
        'id',
        'vendor_id',
        'order_id',
        'order_line_id',
        'type',
        'gross_minor',
        'commission_minor',
        'net_minor',
        'currency',
        'state',
        'payout_id',
        'idempotency_key',
        'created_at',
    ];
}
