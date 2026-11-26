<?php

declare(strict_types=1);

namespace App\Models;

use Siro\Core\Model;

final class ReturnLine extends Model
{
    protected string $table = 'return_lines';
    protected bool $timestamps = false;
    protected array $casts = ['qty' => 'int'];
    protected array $fillable = ['id', 'return_id', 'order_line_id', 'qty', 'reason', 'disposition', 'condition'];
}
