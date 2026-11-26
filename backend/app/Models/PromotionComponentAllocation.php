<?php
declare(strict_types=1);
namespace App\Models;
use Siro\Core\Model;
final class PromotionComponentAllocation extends Model
{
    protected string $table = 'promotion_component_allocations';
    protected bool $timestamps = false;
    protected array $casts = ['discount_minor' => 'int', 'minor_unit' => 'int'];
    protected array $fillable = ['id','order_id','line_id','component_id','promotion_id','currency','minor_unit','discount_minor','idempotency_key','evidence_json','created_at'];
}
