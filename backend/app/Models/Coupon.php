<?php

declare(strict_types=1);

namespace App\Models;

use Siro\Core\Model;

final class Coupon extends Model
{
    protected string $table = 'coupons';

    protected bool $timestamps = false;

    protected array $fillable = [
        'id',
        'code',
        'rules',
        'state',
    ];
}
