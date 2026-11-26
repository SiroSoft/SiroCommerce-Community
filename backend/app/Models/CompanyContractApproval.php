<?php

declare(strict_types=1);

namespace App\Models;

use Siro\Core\Model;

final class CompanyContractApproval extends Model
{
    protected string $table = 'company_contract_approvals';
    protected array $fillable = ['id', 'contract_id', 'company_id', 'user_id', 'status', 'idempotency_key'];
    protected array $casts = ['user_id' => 'int'];
}
