<?php

declare(strict_types=1);

namespace App\Models;

use Siro\Core\Model;

final class ProductSubscriptionPlan extends Model
{
    protected string $table = 'product_subscription_plans';
    protected bool $timestamps = false;
    protected array $casts = ['sort' => 'int'];
    protected array $fillable = ['id', 'product_id', 'variant_id', 'plan_id', 'sort', 'created_at', 'updated_at'];
}
