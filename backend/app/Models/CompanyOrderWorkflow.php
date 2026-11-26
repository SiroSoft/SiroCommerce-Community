<?php
declare(strict_types=1);
namespace App\Models;
use Siro\Core\Model;
final class CompanyOrderWorkflow extends Model { protected string $table = 'company_order_workflows'; protected array $fillable = ['order_id','company_id','state','submitted_by','approved_by','rejected_by','rejection_reason']; protected array $casts = ['submitted_by'=>'int','approved_by'=>'int','rejected_by'=>'int']; }
