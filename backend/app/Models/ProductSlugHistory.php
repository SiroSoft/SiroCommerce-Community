<?php
declare(strict_types=1);
namespace App\Models;
use Siro\Core\Model;
final class ProductSlugHistory extends Model
{
    protected string $table = 'product_slug_history';
    protected bool $timestamps = false;
    protected array $fillable = ['id', 'product_id', 'slug', 'created_at'];
}
