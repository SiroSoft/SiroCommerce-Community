<?php
declare(strict_types=1);
namespace App\Models;
use Siro\Core\Model;
use Siro\Core\DB\Relations\HasMany;
final class CmsMenu extends Model
{
    protected string $table = 'cms_menus';
    protected array $fillable = ['id', 'key', 'locale', 'status', 'version'];
    protected array $casts = ['version' => 'int'];
    public function items(): HasMany { return $this->hasMany(CmsMenuItem::class, 'menu_id', 'id'); }
}
