<?php

declare(strict_types=1);

namespace App\Models;

use Siro\Core\Model;

final class PaymentProof extends Model
{
    protected string $table = 'payment_proofs';

    protected bool $timestamps = false;

    protected array $fillable = [
        'id',
        'payment_id',
        'order_id',
        'reference',
        'file_url',
        'file_hash',
        'uploaded_by',
        'channel',
        'idempotency_key',
        'created_at',
    ];
}
