<?php

declare(strict_types=1);

namespace App\Models;

use Siro\Core\Model;

final class DigitalAsset extends Model
{
    protected string $table = 'digital_assets';
    protected bool $timestamps = false;
    protected array $casts = ['download_limit' => 'int', 'size_bytes' => 'int', 'expires_at' => 'datetime', 'revoked_at' => 'datetime', 'malware_scanned_at' => 'datetime'];
    protected array $fillable = ['id', 'variant_id', 'name', 'url', 'access_key', 'download_limit', 'expires_at', 'content_sha256', 'content_type', 'size_bytes', 'revoked_at', 'malware_scan_status', 'malware_scanned_at'];
}
