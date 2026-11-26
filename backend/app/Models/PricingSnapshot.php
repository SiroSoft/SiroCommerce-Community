<?php

declare(strict_types=1);

namespace App\Models;

use Siro\Core\Model;

final class PricingSnapshot extends Model
{
    protected string $table = 'pricing_snapshots';
    protected bool $timestamps = false;
    protected array $casts = ['subtotal_minor' => 'int', 'discount_minor' => 'int', 'shipping_minor' => 'int', 'tax_minor' => 'int', 'grand_total_minor' => 'int'];
    protected array $fillable = ['id', 'owner_type', 'owner_id', 'currency', 'engine_version', 'lines_json', 'rules_json', 'taxes_json', 'promotion_evidence_json', 'subtotal_minor', 'discount_minor', 'shipping_minor', 'tax_minor', 'grand_total_minor', 'expires_at', 'created_at'];
}
