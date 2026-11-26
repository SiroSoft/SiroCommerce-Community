<?php
declare(strict_types=1);
namespace App\Resources;
use Siro\Core\Resource;
final class BiExportResource extends Resource
{
    public function toArray(): array
    {
        $fields = ['id', 'dataset', 'format', 'schema_version', 'from_at', 'to_at', 'snapshot_at', 'state', 'rows_written', 'batch_size', 'cursor_at', 'cursor_id', 'store_id', 'channel_id', 'download_url', 'completed_at', 'created_at', 'updated_at'];
        $out = []; foreach ($fields as $field) if (array_key_exists($field, $this->data)) $out[$field] = $this->data[$field]; return $out;
    }
}
