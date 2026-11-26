<?php
declare(strict_types=1);
namespace App\Models;
use Siro\Core\Model;
final class CompanyCreditReservation extends Model { protected string $table = 'company_credit_reservations'; protected array $fillable = ['id','company_id','order_id','currency','amount_minor','state','idempotency_key']; protected array $casts = ['amount_minor'=>'int']; }
