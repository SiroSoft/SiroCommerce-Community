<?php

declare(strict_types=1);

namespace App\Models;

use Siro\Core\Model;

final class CustomerGroup extends Model
{
    protected string $table = 'pricing_customer_groups';
    protected bool $timestamps = false;
    protected array $fillable = ['id', 'code', 'name', 'state', 'created_at'];
}
