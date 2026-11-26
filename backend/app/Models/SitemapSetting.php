<?php

declare(strict_types=1);

namespace App\Models;

use Siro\Core\Model;

final class SitemapSetting extends Model
{
    protected string $table = 'sitemap_settings';
    protected bool $timestamps = false;
    protected array $fillable = ['id','store_id','channel_id','settings_json','version','updated_at'];
}
