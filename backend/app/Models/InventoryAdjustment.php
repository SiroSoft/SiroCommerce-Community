<?php
declare(strict_types=1);
namespace App\Models;
use Siro\Core\Model;
final class InventoryAdjustment extends Model { protected string $table = 'inventory_adjustments'; protected bool $timestamps = false; protected array $fillable = ['id','variant_id','source_id','old_on_hand','new_on_hand','delta','actor','evidence_json','idempotency_key','created_at']; }
