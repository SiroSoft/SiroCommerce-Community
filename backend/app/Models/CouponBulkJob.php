<?php

declare(strict_types=1);

namespace App\Models;

use Siro\Core\Model;

final class CouponBulkJob extends Model
{
    protected string $table = 'coupon_bulk_jobs';
    protected bool $timestamps = false;
    protected array $fillable = ['id','actor_id','status','pattern','count','start','rules','result_json','created_at'];
}
