<?php

declare(strict_types=1);

namespace App\Support;

/** Redacts provider evidence before it can reach durable storage or logs. */
final class ProviderEvidence
{
    /**
     * @param array<string, mixed> $data
     * @return array<mixed, mixed>
     */
    public static function redact(array $data): array
    {
        $blocked = ['accesstoken', 'apikey', 'clientid', 'clientsecret', 'secret', 'token', 'password', 'authorization', 'signature', 'pan', 'cvv'];
        return self::redactValue($data, $blocked);
    }

    /**
     * @param array<mixed, mixed> $value
     * @param array<int, string> $blocked
     * @return array<mixed, mixed>
     */
    private static function redactValue(array $value, array $blocked): array
    {
        foreach ($value as $key => $nested) {
            if (self::isBlockedKey((string) $key, $blocked)) {
                $value[$key] = '[redacted]';
                continue;
            }
            if (is_array($nested)) $value[$key] = self::redactValue($nested, $blocked);
        }
        return $value;
    }

    private static function normalize(string $key): string
    {
        return strtolower((string) preg_replace('/[^a-z0-9]/i', '', $key));
    }

    /** @param array<int, string> $blocked */
    private static function isBlockedKey(string $key, array $blocked): bool
    {
        $normalized = self::normalize($key);
        if (in_array($normalized, $blocked, true)) return true;
        foreach (['token', 'secret', 'apikey', 'authorization', 'password', 'signature'] as $suffix) {
            if (str_ends_with($normalized, $suffix)) return true;
        }
        return false;
    }
}
