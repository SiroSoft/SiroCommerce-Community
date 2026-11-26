<?php

declare(strict_types=1);

namespace App\Models;

use Siro\Core\Model;

final class SeoMeta extends Model
{
    protected string $table = 'seo_meta';

    protected bool $timestamps = false;

    protected array $fillable = [
        'entity_type',
        'entity_id',
        'title',
        'description',
        'canonical',
        'robots',
        'og_image',
        'schema_json',
    ];
}
