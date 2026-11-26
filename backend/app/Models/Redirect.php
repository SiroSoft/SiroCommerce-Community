<?php

declare(strict_types=1);

namespace App\Models;

use Siro\Core\Model;

final class Redirect extends Model
{
    protected string $table = 'redirects';

    protected bool $timestamps = false;

    protected array $casts = [
        'code' => 'int',
    ];

    protected array $fillable = [
        'from_path',
        'to_path',
        'code',
    ];
}
