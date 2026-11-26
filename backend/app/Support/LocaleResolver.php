<?php

declare(strict_types=1);

namespace App\Support;

use Siro\Core\Request;

/**
 * Per-request locale: X-Locale header → ?locale= → Accept-Language → APP_LOCALE.
 * Allowlist enforced; unknown falls back to 'en' (skeleton convention).
 */
final class LocaleResolver
{
    /** @var string[] */
    public const ALLOWED = ['en', 'vi', 'ja', 'de', 'zh'];

    public static function resolve(Request $request): string
    {
        $header = strtolower(trim((string) $request->header('X-Locale', '')));
        $resolved = self::match($header);
        if ($resolved !== null) return $resolved;
        $query = strtolower($request->queryString('locale', ''));
        $resolved = self::match($query);
        if ($resolved !== null) return $resolved;
        $accept = strtolower((string) $request->header('Accept-Language', ''));
        $candidates = [];
        foreach (explode(',', $accept) as $part) {
            $parts = explode(';', trim($part));
            $tag = array_shift($parts) ?? '';
            $parameters = $parts;
            $quality = 1.0;
            foreach ($parameters as $parameter) if (str_starts_with(trim($parameter), 'q=')) $quality = (float) substr(trim($parameter), 2);
            $candidates[] = ['tag' => $tag, 'quality' => $quality];
        }
        usort($candidates, static fn (array $a, array $b): int => $b['quality'] <=> $a['quality']);
        foreach ($candidates as $candidate) {
            $resolved = self::match($candidate['tag']);
            if ($resolved !== null) return $resolved;
        }
        return 'en';
    }

    private static function match(string $value): ?string
    {
        $base = explode('-', str_replace('_', '-', trim($value)))[0];
        return in_array($base, self::ALLOWED, true) ? $base : null;
    }
}
