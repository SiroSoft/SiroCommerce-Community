<?php

declare(strict_types=1);

namespace App\Models;

use Siro\Core\Model;

final class MemberDeal extends Model
{
    protected string $table = 'member_deals';
    protected bool $timestamps = false;
    protected array $casts = ['percent_off' => 'int'];
    protected array $fillable = ['id', 'name', 'kind', 'min_tier', 'percent_off', 'starts_at', 'ends_at', 'state', 'created_at'];
}
