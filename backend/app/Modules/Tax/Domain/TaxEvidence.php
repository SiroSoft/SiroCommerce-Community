<?php

declare(strict_types=1);

namespace App\Modules\Tax\Domain;

final class TaxEvidence
{
    /**
     * @param array<string, mixed> $value
     * @return array<string, mixed>
     */
    public static function canonical(array $value): array
    {
        ksort($value);
        foreach ($value as $key => $item) {
            if (is_array($item) && self::isAssociative($item)) {
                /** @var array<string, mixed> $item */
                $value[$key] = self::canonical($item);
            }
        }
        return $value;
    }

    /** @param array<string, mixed> $value */
    public static function hash(array $value): string
    {
        return hash('sha256', json_encode(self::canonical($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    /** @param array<mixed> $value */
    private static function isAssociative(array $value): bool
    {
        return array_keys($value) !== range(0, count($value) - 1);
    }
}
