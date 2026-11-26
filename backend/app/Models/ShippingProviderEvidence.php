<?php

declare(strict_types=1);

namespace App\Models;

use Siro\Core\Model;

final class ShippingProviderEvidence extends Model
{
    protected string $table = 'shipping_provider_evidence';
    protected array $fillable = ['id', 'provider', 'operation', 'idempotency_key', 'provider_reference', 'status', 'payload_hash', 'request_json', 'response_json'];
}
