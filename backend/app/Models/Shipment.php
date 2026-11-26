<?php

declare(strict_types=1);

namespace App\Models;

use Siro\Core\Model;

final class Shipment extends Model
{
    protected string $table = 'shipments';

    protected bool $timestamps = false;

    protected array $fillable = [
        'id',
        'order_id',
        'provider',
        'tracking',
        'state',
    ];
}
