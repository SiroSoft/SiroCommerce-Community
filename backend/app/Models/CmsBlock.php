<?php

declare(strict_types=1);

namespace App\Models;

use Siro\Core\Model;

final class CmsBlock extends Model
{
    protected string $table = 'cms_blocks';
    protected array $fillable = ['id', 'page_id', 'type', 'content', 'sort', 'status', 'version', 'media_json'];
    protected array $casts = ['sort' => 'int', 'version' => 'int', 'created_at' => 'datetime', 'updated_at' => 'datetime'];
}
