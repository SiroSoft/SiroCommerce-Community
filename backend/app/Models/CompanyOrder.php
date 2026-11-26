<?php
declare(strict_types=1);
namespace App\Models;
use Siro\Core\Model;
final class CompanyOrder extends Model { protected string $table='company_orders'; protected array $fillable=['order_id','company_id','channel_id']; }
