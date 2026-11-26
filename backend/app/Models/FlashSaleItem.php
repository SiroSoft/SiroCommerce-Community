<?php
declare(strict_types=1);
namespace App\Models;
use Siro\Core\Model;
final class FlashSaleItem extends Model
{
    protected string $table = 'flash_sale_items';
    protected bool $timestamps = false;
    protected array $fillable = ['id','sale_id','variant_id','sale_price_minor','cap_qty','sold_qty','created_at'];
}
