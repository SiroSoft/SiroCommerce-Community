<?php

declare(strict_types=1);

namespace App\Models;

use Siro\Core\Model;

final class PromotionRule extends Model
{
    protected string $table = 'promotion_rules';

    protected bool $timestamps = false;

    protected array $casts = [
        'value' => 'int',
        'min_subtotal_minor' => 'int',
        'max_uses' => 'int',
        'uses' => 'int',
    ];

    protected array $fillable = [
        'id',
        'name',
        'kind',
        'value',
        'min_subtotal_minor',
        'category_slugs_json',
        'starts_at',
        'ends_at',
        'max_uses',
        'uses',
        'state',
        'created_at',
        'updated_at',
    ];
}
