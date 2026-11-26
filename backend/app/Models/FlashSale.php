<?php
declare(strict_types=1);
namespace App\Models;
use Siro\Core\Model;
final class FlashSale extends Model
{
    protected string $table = 'flash_sales';
    protected bool $timestamps = false;
    protected array $fillable = ['id','name','state','starts_at','ends_at','priority','min_tier','created_at','updated_at'];
}
