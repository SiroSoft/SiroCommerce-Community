<?php

declare(strict_types=1);

namespace App\Models;

use Siro\Core\Model;

final class CompanyCreditAccount extends Model
{
    protected string $table = 'company_credit_accounts';
    protected array $fillable = ['id', 'company_id', 'currency', 'limit_minor', 'status'];
    protected array $casts = ['limit_minor' => 'int'];
}
