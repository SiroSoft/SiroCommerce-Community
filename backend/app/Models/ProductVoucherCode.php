<?php

declare(strict_types=1);

namespace App\Models;

use Siro\Core\Model;

final class ProductVoucherCode extends Model
{
    protected string $table = 'product_voucher_codes';
    protected bool $timestamps = false;
    protected array $fillable = ['id', 'product_id', 'code_hash', 'code_mask', 'state', 'expires_at', 'redeemed_by', 'redeemed_order_id', 'redeemed_at', 'created_at', 'idempotency_key'];
}
