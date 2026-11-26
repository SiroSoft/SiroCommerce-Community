<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Typed row access. Repositories return array<string, mixed> (Model::toArray);
 * services/controllers must narrow with these guards instead of blind casts,
 * so PHPStan level max stays green and bad shapes fail fast.
 */
use Siro\Core\Request;

final class Arr
{
    /**
     * Normalize a query result row: Model::toArray() or an already-raw array
     * (QueryBuilder::whereRaw returns the base builder whose get() yields
     * raw rows, not models).
     *
     * @return array<string, mixed>
     */
    public static function row(mixed $m): array
    {
        if (is_array($m)) {
            $out = [];
            foreach ($m as $k => $v) {
                if (is_string($k)) {
                    $out[$k] = $v;
                }
            }
            return $out;
        }
        if ($m instanceof \Siro\Core\Model) {
            /** @var array<string, mixed> $out */
            $out = $m->toArray();
            return $out;
        }
        return [];
    }

    /**
     * Normalize datetime values: casts may yield DateTime objects or strings.
     * Always returns 'Y-m-d H:i:s' or '' when unparseable.
     *
     * @param array<string, mixed> $row
     */
    public static function dt(array $row, string $key): string
    {
        $value = $row[$key] ?? null;
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }
        if (is_string($value) && $value !== '') {
            return $value;
        }
        return '';
    }

    /** Route params are mixed — narrow with a clear error. */
    public static function requiredParam(Request $request, string $key): string
    {
        $value = $request->param($key);
        if (!is_string($value) || $value === '') {
            throw new \InvalidArgumentException("Missing route param: {$key}");
        }
        return $value;
    }

    /** @param array<string, mixed> $row */
    public static function str(array $row, string $key, string $default = ''): string
    {
        $value = $row[$key] ?? $default;
        return is_string($value) ? $value : $default;
    }

    /** @param array<string, mixed> $row */
    public static function requiredStr(array $row, string $key): string
    {
        $value = $row[$key] ?? null;
        if (!is_string($value) || $value === '') {
            throw new \InvalidArgumentException("Missing required string: {$key}");
        }
        return $value;
    }

    /** @param array<string, mixed> $row */
    public static function nullableStr(array $row, string $key): ?string
    {
        $value = $row[$key] ?? null;
        if ($value === null) {
            return null;
        }
        return is_string($value) ? $value : null;
    }

    /** @param array<string, mixed> $row */
    public static function int(array $row, string $key, int $default = 0): int
    {
        $value = $row[$key] ?? $default;
        if (is_int($value)) {
            return $value;
        }
        if (is_bool($value)) {
            return $value ? 1 : 0;
        }
        if (is_numeric($value)) {
            return (int) $value;
        }
        return $default;
    }

    /** @param array<string, mixed> $row */
    public static function requiredInt(array $row, string $key): int
    {
        $value = $row[$key] ?? null;
        if (is_int($value)) {
            return $value;
        }
        if (is_numeric($value)) {
            return (int) $value;
        }
        throw new \InvalidArgumentException("Missing required int: {$key}");
    }
}
