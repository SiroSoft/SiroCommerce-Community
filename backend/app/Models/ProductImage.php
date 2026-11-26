<?php

declare(strict_types=1);

namespace App\Models;

use Siro\Core\Model;

final class ProductImage extends Model
{
    protected string $table = 'product_images';

    protected array $casts = [
        'sort' => 'int',
        'width' => 'int',
        'height' => 'int',
        'size_bytes' => 'int',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    protected array $fillable = [
        'id', 'product_id', 'url', 'alt', 'sort', 'type', 'mime_type', 'width', 'height',
        'size_bytes', 'checksum', 'title', 'role', 'storage_path',
        'variants_json', 'placeholder',
    ];
}
