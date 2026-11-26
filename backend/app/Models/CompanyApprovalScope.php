<?php
declare(strict_types=1);
namespace App\Models;
use Siro\Core\Model;
final class CompanyApprovalScope extends Model { protected string $table = 'company_approval_scopes'; protected array $fillable = ['id', 'company_id', 'scope', 'channel_id', 'approval_limit_minor', 'active']; protected array $casts = ['approval_limit_minor' => 'int', 'active' => 'int']; }
