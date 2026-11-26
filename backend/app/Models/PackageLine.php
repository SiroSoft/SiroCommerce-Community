<?php

declare(strict_types=1);

namespace App\Models;

use Siro\Core\Model;

final class PackageLine extends Model
{
    protected string $table = 'package_lines';
    protected bool $timestamps = false;
    protected array $casts = ['qty' => 'int'];
    protected array $fillable = ['id', 'package_id', 'fulfillment_line_id', 'qty'];
}
