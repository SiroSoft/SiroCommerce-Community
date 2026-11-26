<?php
declare(strict_types=1);
namespace App\Models;
use Siro\Core\Model;
final class CatalogImport extends Model
{
    protected string $table = 'catalog_imports';
    protected bool $timestamps = false;
    protected array $casts = ['schema_version' => 'int', 'rows_seen' => 'int', 'rows_imported' => 'int'];
    protected array $fillable = ['id', 'source_fingerprint', 'format', 'schema_version', 'state', 'rows_seen', 'rows_imported', 'errors_json', 'created_at', 'completed_at'];
}
