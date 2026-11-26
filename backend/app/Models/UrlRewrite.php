<?php

declare(strict_types=1);

namespace App\Models;

use Siro\Core\Model;

final class UrlRewrite extends Model
{
    protected string $table = 'url_rewrites';
    protected bool $timestamps = false;
    protected array $fillable = ['id','request_path','target_path','redirect_type','store_id','channel_id','created_by','created_at','updated_at'];
}
