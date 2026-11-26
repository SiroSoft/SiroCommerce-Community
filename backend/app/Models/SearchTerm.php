<?php

declare(strict_types=1);

namespace App\Models;

use Siro\Core\Model;

final class SearchTerm extends Model
{
    protected string $table = 'search_terms';
    protected bool $timestamps = false;
    protected array $fillable = ['id','term','hits','misses','results_avg','store_id','channel_id','updated_at'];
}
