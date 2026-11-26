<?php
declare(strict_types=1);
namespace App\Models;
use Siro\Core\Model;
final class MemberPrice extends Model
{
    protected string $table = 'member_prices';
    protected bool $timestamps = false;
    protected array $fillable = ['id','variant_id','tier','price_minor','currency','created_at','updated_at'];
}
