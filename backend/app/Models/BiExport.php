<?php
declare(strict_types=1);
namespace App\Models;
use Siro\Core\Model;
final class BiExport extends Model
{
    protected string $table = 'bi_exports'; protected bool $timestamps = false;
    protected array $casts = ['schema_version' => 'int', 'rows_written' => 'int', 'batch_size' => 'int'];
    protected array $fillable = ['id', 'dataset', 'format', 'schema_version', 'from_at', 'to_at', 'snapshot_at', 'state', 'rows_written', 'batch_size', 'cursor_at', 'cursor_id', 'storage_path', 'download_url', 'last_error', 'completed_at', 'created_at', 'updated_at', 'store_id', 'channel_id'];
}
