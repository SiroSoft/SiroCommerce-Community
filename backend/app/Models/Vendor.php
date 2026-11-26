<?php

declare(strict_types=1);

namespace App\Models;

use Siro\Core\Model;

final class Vendor extends Model
{
    protected string $table = 'vendors';

    protected array $casts = [
        'commission_bps' => 'int',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    protected array $fillable = [
        'id',
        'name',
        'slug',
        'status',
        'commission_bps',
        'contact_email',
        'description',
        'owner_customer_id',
    ];
}
