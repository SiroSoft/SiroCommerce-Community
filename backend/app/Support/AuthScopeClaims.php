<?php
declare(strict_types=1);

namespace App\Support;

final class AuthScopeClaims
{
    /**
     * @param array<string, mixed> $user
     * @return array<string, list<string>>
     */
    public static function fromUser(array $user): array
    {
        $claims = [];
        foreach (['tenant_ids', 'seller_ids', 'site_ids'] as $name) {
            $value = $user[$name] ?? null;
            if (is_string($value)) {
                $decoded = json_decode($value, true);
                $value = is_array($decoded) ? $decoded : [$value];
            }
            $values = is_array($value) ? $value : ($value === null ? [] : [$value]);
            $normalized = [];
            foreach ($values as $item) {
                if (is_scalar($item) && (string) $item !== '') $normalized[] = (string) $item;
            }
            if ($normalized !== []) $claims[$name] = array_values(array_unique($normalized));
        }
        return $claims;
    }
}
