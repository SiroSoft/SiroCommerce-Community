<?php

declare(strict_types=1);

namespace App\Models;

use Siro\Core\Model;

final class Address extends Model
{
    protected string $table = 'addresses';
    protected bool $timestamps = false;
    protected array $fillable = ['id', 'customer_id', 'label', 'line1', 'city', 'phone', 'country', 'region', 'postal_code'];
}
