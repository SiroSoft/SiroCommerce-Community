<?php
declare(strict_types=1);
namespace App\Models;
use Siro\Core\Model;
final class Brand extends Model
{
    protected string $table = 'brands';
    protected bool $timestamps = true;
    protected array $fillable = ['id', 'name', 'slug', 'archived_at'];
}
