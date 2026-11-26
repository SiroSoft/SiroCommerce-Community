<?php
declare(strict_types=1);

namespace App\Models;

use Siro\Core\Model;

final class PromotionUsageReconciliation extends Model
{
    protected string $table = 'promotion_usage_reconciliations';
    protected bool $timestamps = false;
    protected array $casts = ['released_discount_minor' => 'int', 'minor_unit' => 'int'];
    protected array $fillable = ['id', 'coupon_id', 'order_id', 'idempotency_key', 'released_discount_minor', 'currency', 'minor_unit', 'reason', 'evidence_json', 'created_at'];
}
