<?php

declare(strict_types=1);

namespace App\Models;

use Siro\Core\Model;

final class CompanyContract extends Model
{
    protected string $table = 'company_contracts';
    protected array $fillable = ['id', 'company_id', 'contract_number', 'status', 'credit_limit_minor', 'currency', 'starts_at', 'ends_at'];
    protected array $casts = ['credit_limit_minor' => 'int'];
}
