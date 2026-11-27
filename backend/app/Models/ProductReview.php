<?php

declare(strict_types=1);

namespace App\Models;

use Siro\Core\Model;

final class ProductReview extends Model
{
    protected string $table = 'product_reviews';
    protected bool $timestamps = false;
    protected array $fillable = ['id', 'product_id', 'variant_id', 'customer_id', 'order_id', 'order_line_id', 'rating', 'title', 'body', 'status', 'moderation_note', 'verified_purchase', 'purchase_evidence_json', 'images_json', 'created_at', 'updated_at'];
    protected array $casts = ['rating' => 'int', 'verified_purchase' => 'bool'];
}
