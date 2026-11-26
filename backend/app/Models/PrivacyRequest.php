<?php
declare(strict_types=1);
namespace App\Models;
use Siro\Core\Model;
final class PrivacyRequest extends Model
{
    protected string $table = 'privacy_requests';
    protected bool $timestamps = false;
    protected array $fillable = ['id','customer_id','type','state','idempotency_key','fingerprint','result_json','legal_hold','created_at','completed_at'];
}
