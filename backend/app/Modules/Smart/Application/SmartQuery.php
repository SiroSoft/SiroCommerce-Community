<?php

declare(strict_types=1);

namespace App\Modules\Smart\Application;

/**
 * Query normalization for the suggest index (spec SMART_SKILLS_SPEC §0):
 * lowercase, collapsed whitespace, ≤64 chars, and PII-shaped input
 * (emails, phones, URLs, long digit runs) is dropped entirely.
 */
final class SmartQuery
{
    public static function normalize(mixed $value): ?string
    {
        if (!is_string($value)) return null;
        $query = mb_strtolower(preg_replace('/\s+/u', ' ', trim($value)) ?? '');
        if ($query === '' || mb_strlen($query) > 64 || preg_match('/[\r\n]/u', $query) === 1) return null;
        if (preg_match('/\S+@\S+\.\S+/u', $query) === 1) return null;
        if (preg_match('/https?:\/\/|www\./iu', $query) === 1) return null;
        if (preg_match('/\d[\d\s().-]{6,}\d/u', $query) === 1) return null;
        return $query;
    }
}
