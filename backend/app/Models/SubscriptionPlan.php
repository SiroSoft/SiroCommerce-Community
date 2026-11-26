<?php
declare(strict_types=1);
namespace App\Models;
use Siro\Core\Model;
final class SubscriptionPlan extends Model
{
    protected string $table = 'subscription_plans';
    protected bool $timestamps = false;
    protected array $casts = ['amount_minor' => 'int', 'interval_count' => 'int', 'trial_period_count' => 'int'];
    protected array $fillable = ['id', 'name', 'amount_minor', 'currency', 'interval', 'interval_count', 'state', 'provider', 'metadata_json', 'provider_authorization_state', 'trial_period', 'trial_period_count', 'created_at', 'updated_at'];
}
