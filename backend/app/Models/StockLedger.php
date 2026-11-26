<?php

declare(strict_types=1);

namespace App\Models;

use Siro\Core\Model;

final class StockLedger extends Model
{
    protected string $table = 'stock_ledger';

    protected bool $timestamps = false;

    protected array $casts = [
        'delta' => 'int',
        'created_at' => 'datetime',
    ];

    protected array $fillable = [
        'id',
        'variant_id',
        'source_id',
        'delta',
        'reason',
        'ref_type',
        'ref_id',
        'actor',
        'created_at',
    ];
}
