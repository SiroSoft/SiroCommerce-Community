<?php
declare(strict_types=1);
namespace App\Models;
use Siro\Core\Model;
final class Category extends Model
{
    protected string $table = 'categories';
    protected bool $timestamps = false;
    protected array $fillable = ['id', 'parent_id', 'name', 'slug', 'sort', 'archived_at', 'description', 'color', 'icon', 'sort_order', 'is_active'];
}
