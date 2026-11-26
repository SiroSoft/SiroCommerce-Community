<?php

declare(strict_types=1);

namespace App\Models;

use Siro\Core\Model;
use Siro\Core\DB\Relations\HasMany;

final class CmsPage extends Model
{
    protected string $table = 'cms_pages';
    protected array $fillable = ['id', 'slug', 'title', 'body', 'status', 'locale', 'store_id', 'channel_id', 'currency', 'published_at', 'scheduled_at', 'version', 'canonical', 'seo_title', 'seo_description', 'seo_robots', 'seo_schema_json', 'media_json'];
    protected array $casts = ['published_at' => 'datetime', 'scheduled_at' => 'datetime', 'version' => 'int', 'created_at' => 'datetime', 'updated_at' => 'datetime'];

    public function blocks(): HasMany
    {
        return $this->hasMany(CmsBlock::class, 'page_id', 'id');
    }
}
