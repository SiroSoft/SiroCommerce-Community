<?php

declare(strict_types=1);

namespace App\Modules\Integration\Domain;

/** Redacts credentials and direct customer/payment secrets at the external boundary. */
final class IntegrationRedactor
{
    private const BLOCKED = [
        'password', 'secret', 'token', 'access_token', 'refresh_token', 'api_key',
        'authorization', 'signature', 'pan', 'cvv', 'card_number', 'payment_method',
        'provider_payload', 'customer_email', 'email', 'phone', 'address',
    ];

    /**
     * @param array<mixed,mixed> $value
     * @return array<mixed,mixed>
     */
    public static function redact(array $value): array
    {
        foreach ($value as $key => $item) {
            $normalized = strtolower((string) preg_replace('/[^a-z0-9]/i', '', (string) $key));
            $blocked = array_map(static fn (string $name): string => str_replace('_', '', $name), self::BLOCKED);
            if (in_array($normalized, $blocked, true)) {
                $value[$key] = '[redacted]';
            } elseif (is_array($item)) {
                $value[$key] = self::redact($item);
            }
        }
        return $value;
    }
}
