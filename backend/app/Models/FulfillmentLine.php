<?php

declare(strict_types=1);

namespace App\Models;

use Siro\Core\Model;

final class FulfillmentLine extends Model
{
    protected string $table = 'fulfillment_lines';
    protected bool $timestamps = false;
    protected array $casts = ['qty' => 'int'];
    protected array $fillable = ['id', 'fulfillment_id', 'order_line_id', 'qty', 'source_id', 'substitution_variant_id', 'reservation_id'];
}
