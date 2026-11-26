<?php

declare(strict_types=1);

namespace App\Extension;

/**
 * Minimal semver constraint check (no dependencies): supports exact
 * versions ("1.0.14") and caret ranges ("^1.0"). Used to enforce
 * extension ↔ core/commerce compatibility (spec docs/07).
 */
final class VersionConstraint
{
    /** @return array{int, int, int} */
    private static function parse(string $version): array
    {
        $parts = explode('.', ltrim(trim($version), 'v'));
        $parts = array_pad($parts, 3, '0');
        return [(int) $parts[0], (int) $parts[1], (int) $parts[2]];
    }

    public static function satisfies(string $version, string $constraint): bool
    {
        $constraint = trim($constraint);
        if ($constraint === '' || $constraint === '*') {
            return true;
        }
        if (str_starts_with($constraint, '^')) {
            [$maj, $min, $patch] = self::parse($version);
            [$cMaj, $cMin, $cPatch] = self::parse(substr($constraint, 1));
            if ($maj !== $cMaj) {
                return false;
            }
            if ($min < $cMin || ($cMaj === 0 && $min !== $cMin)) {
                return false;
            }
            if ($min === $cMin && $patch < $cPatch) {
                return false;
            }
            if ($cMaj === 0 && $cMin === 0 && $min === 0 && $patch >= $cPatch) return true;
            return true;
        }
        return self::parse($version) === self::parse($constraint);
    }
}
