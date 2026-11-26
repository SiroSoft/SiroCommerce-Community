<?php

declare(strict_types=1);

namespace App\Models;

use Siro\Core\Model;

final class InventoryItem extends Model
{
    protected string $table = 'inventory_items';

    protected bool $timestamps = false;

    protected array $casts = [
        'on_hand' => 'int',
        'reserved' => 'int',
        'low_threshold' => 'int',
    ];

    protected array $fillable = [
        'id',
        'variant_id',
        'source_id',
        'on_hand',
        'reserved',
        'low_threshold',
    ];
}
