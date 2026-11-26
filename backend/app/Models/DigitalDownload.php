<?php

declare(strict_types=1);

namespace App\Models;

use Siro\Core\Model;

final class DigitalDownload extends Model
{
    protected string $table = 'digital_downloads';
    protected bool $timestamps = false;
    protected array $casts = ['download_count' => 'int', 'download_limit' => 'int', 'expires_at' => 'datetime', 'last_downloaded_at' => 'datetime', 'revoked_at' => 'datetime'];
    protected array $fillable = ['id', 'asset_id', 'order_id', 'token_hash', 'download_count', 'download_limit', 'expires_at', 'last_downloaded_at', 'revoked_at', 'evidence_json'];
}
