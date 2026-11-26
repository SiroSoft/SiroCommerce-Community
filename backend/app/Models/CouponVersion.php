<?php

declare(strict_types=1);

namespace App\Models;

use Siro\Core\Model;

final class CouponVersion extends Model
{
    protected string $table = 'coupon_versions';
    protected bool $timestamps = false;
    protected array $fillable = ['id','coupon_id','version','code','rules','actor_id','created_at'];
}
