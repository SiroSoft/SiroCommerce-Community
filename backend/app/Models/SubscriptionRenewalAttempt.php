<?php
declare(strict_types=1);
namespace App\Models;
use Siro\Core\Model;
final class SubscriptionRenewalAttempt extends Model
{
    protected string $table = 'subscription_renewal_attempts';
    protected bool $timestamps = false;
    protected array $casts = ['amount_minor' => 'int', 'attempts' => 'int'];
    protected array $fillable = ['id', 'subscription_id', 'idempotency_key', 'state', 'amount_minor', 'currency', 'provider', 'payment_operation_id', 'order_id', 'invoice_id', 'evidence_json', 'last_error', 'attempts', 'next_attempt_at', 'created_at', 'completed_at'];
}
