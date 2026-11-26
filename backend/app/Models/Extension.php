<?php

declare(strict_types=1);

namespace App\Models;

use Siro\Core\Model;

final class Extension extends Model
{
    protected string $table = 'extensions';

    protected bool $timestamps = false;

    protected array $fillable = [
        'id',
        'version',
        'state',
        'manifest_json',
        'last_error',
        'installed_at',
        'updated_at',
    ];
}
