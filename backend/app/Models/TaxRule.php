<?php

declare(strict_types=1);

namespace App\Models;

use Siro\Core\Model;

final class TaxRule extends Model
{
    protected string $table = 'tax_rules';

    protected bool $timestamps = false;

    protected array $casts = [
        'rate_basis_points' => 'int',
        'priority' => 'int',
        'compound' => 'int',
    ];

    protected array $fillable = [
        'id',
        'code',
        'destination_country',
        'destination_region',
        'destination_postal_code',
        'tax_class',
        'rate_basis_points',
        'source',
        'evidence',
        'priority',
        'state',
        'compound',
        'effective_at',
        'expires_at',
        'created_at',
    ];
}
