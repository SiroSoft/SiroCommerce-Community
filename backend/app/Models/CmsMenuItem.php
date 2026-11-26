<?php
declare(strict_types=1);
namespace App\Models;
use Siro\Core\Model;
final class CmsMenuItem extends Model
{
    protected string $table = 'cms_menu_items';
    protected array $fillable = ['id', 'menu_id', 'label', 'target', 'target_type', 'parent_id', 'sort', 'status'];
    protected array $casts = ['sort' => 'int'];
}
