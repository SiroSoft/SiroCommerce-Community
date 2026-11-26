<?php

declare(strict_types=1);

namespace App\Models;

use Siro\Core\Model;

final class PickupPoint extends Model
{
    protected string $table = 'pickup_points';
    protected bool $timestamps = false;
    protected array $casts = ['sla_hours' => 'int'];
    protected array $fillable = ['id', 'code', 'name', 'store_id', 'source_id', 'address_line', 'city', 'sla_hours', 'state', 'created_at'];
}
