<?php
declare(strict_types=1);
namespace App\Models;
use Siro\Core\Model;
final class CompanyOrderTransition extends Model { protected string $table = 'company_order_transitions'; protected array $fillable = ['id','order_id','from_state','to_state','user_id','idempotency_key','reason']; protected array $casts = ['user_id'=>'int']; }
