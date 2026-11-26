<?php

declare(strict_types=1);

namespace App\Models;

use Siro\Core\Model;

final class Package extends Model
{
    protected string $table = 'packages';
    protected bool $timestamps = false;
    protected array $fillable = ['id', 'fulfillment_id', 'carrier', 'provider_ref', 'state', 'idempotency_key', 'tracking'];
}
