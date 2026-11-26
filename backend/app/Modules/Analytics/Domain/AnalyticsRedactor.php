<?php
declare(strict_types=1);
namespace App\Modules\Analytics\Domain;

final class AnalyticsRedactor
{
    /**
     * @param array<mixed, mixed> $payload
     * @return array<mixed, mixed>
     */
    public static function redact(array $payload): array
    {
        $blocked = ['email', 'name', 'phone', 'address', 'street', 'city', 'postcode', 'postal_code', 'token', 'secret', 'password', 'card', 'cvc', 'raw_body'];
        $out = [];
        foreach ($payload as $key => $value) {
            $normalized = strtolower((string) $key);
            $isBlocked = false;
            foreach ($blocked as $word) if ($normalized === $word || str_contains($normalized, $word)) { $isBlocked = true; break; }
            if ($isBlocked) continue;
            if (is_array($value)) {
                $nested = [];
                foreach ($value as $nestedKey => $nestedValue) $nested[$nestedKey] = $nestedValue;
                $out[$key] = self::redact($nested);
            } elseif (is_scalar($value) || $value === null) {
                $out[$key] = $value;
            }
        }
        return $out;
    }
}
