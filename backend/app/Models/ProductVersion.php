<?php

declare(strict_types=1);

namespace App\Models;

use Siro\Core\Model;

final class ProductVersion extends Model
{
    protected string $table = 'product_versions';
    protected array $casts = ['version' => 'int', 'created_at' => 'datetime'];
    protected array $fillable = ['id', 'product_id', 'version', 'state', 'approval_status', 'snapshot_json', 'created_by', 'created_at'];
}
