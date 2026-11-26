<?php
declare(strict_types=1);
namespace App\Models;
use Siro\Core\Model;
final class InventoryTransfer extends Model { protected string $table = 'inventory_transfers'; protected bool $timestamps = false; protected array $fillable = ['id','variant_id','from_source_id','to_source_id','qty','actor','evidence_json','created_at','state','idempotency_key','dispatched_at','received_at']; }
