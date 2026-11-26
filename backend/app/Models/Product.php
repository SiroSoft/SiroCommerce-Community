<?php

declare(strict_types=1);

namespace App\Models;

use Siro\Core\Model;

/**
 * Commerce product — money in INT minor units (spec 02§3.2).
 *
 * @property string $id
 * @property string $name
 * @property string $slug
 * @property string $status
 * @property string $created_at
 */
final class Product extends Model
{
    protected string $table = 'products';

    protected array $casts = [
        'version' => 'int',
        'allow_backorder' => 'bool',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'archived_at' => 'datetime',
    ];

    protected array $fillable = [
        'id',
        'name',
        'slug',
        'brand_id',
        'category_id',
        'vendor_id',
        'status',
        'description',
        'tags_json',
        'allow_backorder',
        'meta_title',
        'meta_description',
        'version',
        'archived_at',
        'bundle_pricing_mode',
        'bundle_price_minor',
        'type',
        'store_id',
        'channel_id',
        'locale',
         'currency',
         'publish_state',
         'publish_at',
         'unpublish_at',
         'delivery_metadata_json',
         'eligibility_rules_json',
         'approval_status',
         'min_tier',
         'is_preorder',
      ];
}
