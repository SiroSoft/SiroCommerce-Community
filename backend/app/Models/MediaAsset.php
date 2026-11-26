<?php
declare(strict_types=1);
namespace App\Models;
use Siro\Core\Model;
final class MediaAsset extends Model
{
    protected string $table = 'media_assets';
    protected bool $timestamps = false;
    protected array $fillable = ['id','url','storage_path','type','mime_type','size_bytes','width','height','title','alt','created_by','created_at','variants_json','placeholder'];
}
