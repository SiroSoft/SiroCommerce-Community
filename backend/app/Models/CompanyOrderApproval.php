<?php
declare(strict_types=1);
namespace App\Models;
use Siro\Core\Model;
final class CompanyOrderApproval extends Model { protected string $table='company_order_approvals'; protected array $fillable=['id','company_id','order_id','user_id','scope','status','idempotency_key']; protected array $casts=['user_id'=>'int']; }
