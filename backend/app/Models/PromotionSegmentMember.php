<?php
declare(strict_types=1);
namespace App\Models;
use Siro\Core\Model;
final class PromotionSegmentMember extends Model
{
    protected string $table = 'promotion_segment_members';
    protected bool $timestamps = false;
    protected array $fillable = ['segment_id', 'customer_id', 'created_at'];
}
