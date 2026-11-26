<?php
declare(strict_types=1);
namespace App\Models;
use Siro\Core\Model;
final class PromotionCampaign extends Model
{
    protected string $table = 'promotion_campaigns';
    protected bool $timestamps = false;
    protected array $fillable = ['id', 'code', 'state', 'starts_at', 'ends_at', 'rules', 'created_at'];
}
