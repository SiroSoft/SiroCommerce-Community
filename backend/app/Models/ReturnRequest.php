<?php

declare(strict_types=1);

namespace App\Models;

use Siro\Core\Model;

final class ReturnRequest extends Model
{
    protected string $table = 'return_requests';
    protected bool $timestamps = false;
    protected array $fillable = ['id', 'order_id', 'fulfillment_id', 'state', 'idempotency_key', 'reason', 'disposition', 'inspection_note', 'created_at', 'completed_at', 'refund_payment_id', 'refund_amount_minor', 'refund_currency', 'refund_state', 'refund_idempotency_key', 'refund_error', 'label_idempotency_key', 'label_provider', 'label_provider_ref', 'label_tracking', 'label_url', 'label_state', 'label_attempts', 'label_last_error', 'label_locked_at'];
}
