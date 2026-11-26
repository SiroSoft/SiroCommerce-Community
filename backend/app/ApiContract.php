<?php

declare(strict_types=1);

namespace App;

use Siro\Core\Console;

/** Public API/client compatibility metadata. Keep this additive and stable. */
final class ApiContract
{
    public const CURRENT = 'v1';
    public const V2 = 'v2';
    public const SCHEMA_VERSION = 1;

    /** @return array<string, mixed> */
    public static function metadata(): array
    {
        return [
            'commerce' => CommerceVersion::VERSION,
            'core' => Console::VERSION,
            'contract' => self::SCHEMA_VERSION,
            'current_api' => self::CURRENT,
            'supported_apis' => [self::CURRENT, self::V2],
            'pagination' => ['style' => 'page', 'parameters' => ['page', 'per_page'], 'max_per_page' => 100],
            'error_format' => 'envelope',
            'client' => [
                'sdk' => 'typescript',
                'generated_path' => 'backend/generated/sirocommerce.ts',
                'openapi_path' => 'backend/docs/openapi.json',
                'contract' => self::SCHEMA_VERSION,
            ],
            'cart_access' => ['header' => 'X-Cart-Token', 'format' => '64 lowercase hexadecimal characters', 'storage' => 'sha256 digest only'],
            'idempotency' => ['header' => 'Idempotency-Key', 'max_length' => 128, 'format' => 'A-Z a-z 0-9 . _ : -'],
            'deprecations' => [],
            'read_consistency' => 'eventual; writes invalidate relevant read caches',
            'customer_subscriptions' => ['ownership' => 'authenticated customer only', 'pagination' => ['page', 'per_page', 'max_per_page' => 100], 'provider' => 'local lifecycle evidence only'],
            'scope' => ['required_dimensions' => ['store_id', 'channel_id', 'locale', 'currency'], 'optional_dimension' => 'tenant_id', 'headers' => ['X-Tenant-Id', 'X-Store-Id', 'X-Channel-Id', 'X-Locale', 'X-Currency'], 'conflicts' => 'header/query disagreement is rejected'],
            'external_evidence' => ['provider_payment_shipping_erp' => 'BLOCKED_EXTERNAL until independently verified', 'synthetic_pass' => false],
            'events' => ['schema' => 'name.v1', 'delivery' => 'outbox after commit', 'replay' => 'event_id idempotent'],
        ];
    }
}
