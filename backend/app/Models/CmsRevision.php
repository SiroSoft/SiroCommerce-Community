<?php
declare(strict_types=1);
namespace App\Models;
use Siro\Core\Model;
final class CmsRevision extends Model
{
    protected string $table = 'cms_revisions';
    protected bool $timestamps = false;
    protected array $fillable = ['id', 'entity_type', 'entity_id', 'version', 'snapshot_json', 'action', 'actor_id', 'idempotency_key', 'store_id', 'channel_id', 'locale', 'currency', 'created_at'];
    protected array $casts = ['version' => 'int', 'created_at' => 'datetime'];
}
