<?php

declare(strict_types=1);

namespace App\Models;

use Siro\Core\Model;

final class SearchSynonym extends Model
{
    protected string $table = 'search_synonyms';
    protected bool $timestamps = false;
    protected array $fillable = ['id','term','synonyms','store_id','channel_id','created_at','updated_at'];
}
