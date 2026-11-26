<?php

declare(strict_types=1);

namespace App\Models;

use Siro\Core\Model;

final class OrderTransitionRecord extends Model
{
    protected string $table = 'order_transitions';

    protected bool $timestamps = false;

    protected array $casts = [
        'created_at' => 'datetime',
    ];

    protected array $fillable = [
        'id',
        'order_id',
        'from_state',
        'to_state',
        'actor',
        'reason',
        'idempotency_key',
        'created_at',
    ];
}
