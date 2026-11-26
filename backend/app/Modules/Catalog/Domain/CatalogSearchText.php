<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Domain;

final class CatalogSearchText
{
    public const MAX_TOKENS = 6;

    private const FOLD_GROUPS = [
        'a' => 'áàảãạăắằẳẵặâấầẩẫậ',
        'e' => 'éèẻẽẹêếềểễệ',
        'i' => 'íìỉĩị',
        'o' => 'óòỏõọôốồổỗộơớờởỡợ',
        'u' => 'úùủũụưứừửữự',
        'y' => 'ýỳỷỹỵ',
        'd' => 'đ',
    ];

    /** @var array<string, string>|null */
    private static ?array $foldMap = null;

    public static function normalize(string $value): string
    {
        $value = trim($value);
        if ($value === '') return '';
        $value = mb_strtolower($value, 'UTF-8');
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;
        return trim($value);
    }

    public static function fold(string $value): string
    {
        $value = self::normalize($value);
        if ($value === '') return '';
        $value = strtr($value, self::foldMap());
        $value = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $value) ?? $value;
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;
        return trim($value);
    }

    /** @return list<string> */
    public static function tokens(string $query, bool $fold = false): array
    {
        $value = $fold ? self::fold($query) : self::normalize($query);
        if ($value === '') return [];
        $parts = preg_split('/[^\p{L}\p{N}]+/u', $value, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $tokens = [];
        foreach ($parts as $part) {
            $tokens[] = $part;
            if (count($tokens) >= self::MAX_TOKENS) break;
        }
        return $tokens;
    }

    public static function likeContains(string $value): string
    {
        return '%' . self::escapeLike(self::normalize($value)) . '%';
    }

    public static function likeStartsWith(string $value): string
    {
        return self::escapeLike(self::normalize($value)) . '%';
    }

    /** @param list<string> $foldedTokens */
    public static function matchesTokens(string $foldedText, array $foldedTokens): bool
    {
        if ($foldedTokens === []) return true;
        $words = self::words($foldedText);
        foreach ($foldedTokens as $token) {
            if ($token === '') continue;
            if (!self::tokenMatches($token, $foldedText, $words)) return false;
        }
        return true;
    }

    /** @param list<string> $foldedTokens */
    public static function nameScore(string $foldedFullQuery, array $foldedTokens, string $foldedName): int
    {
        $score = 0;
        if ($foldedFullQuery !== '') {
            if (str_starts_with($foldedName, $foldedFullQuery)) $score += 100;
            if (str_contains($foldedName, $foldedFullQuery)) $score += 60;
        }
        $words = self::words($foldedName);
        foreach ($foldedTokens as $token) {
            if ($token !== '' && self::tokenMatches($token, $foldedName, $words)) $score += 20;
        }
        return $score;
    }

    private static function escapeLike(string $value): string
    {
        return str_replace(['~', '%', '_'], ['~~', '~%', '~_'], $value);
    }

    /** @return array<string, string> */
    private static function foldMap(): array
    {
        if (self::$foldMap !== null) return self::$foldMap;
        $map = [];
        foreach (self::FOLD_GROUPS as $replacement => $chars) {
            foreach (mb_str_split($chars) as $char) $map[$char] = $replacement;
        }
        return self::$foldMap = $map;
    }

    /** @return list<string> */
    private static function words(string $text): array
    {
        if ($text === '') return [];
        return preg_split('/\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }

    /** @param list<string> $words */
    private static function tokenMatches(string $token, string $text, array $words): bool
    {
        $tokenLength = mb_strlen($token, 'UTF-8');
        if ($tokenLength < 4) return str_contains($text, $token);
        if (str_contains($text, $token)) return true;
        $maxDistance = $tokenLength >= 7 ? 2 : 1;
        foreach ($words as $word) {
            $wordLength = mb_strlen($word, 'UTF-8');
            if ($wordLength === 0) continue;
            if (str_starts_with($word, $token)) return true;
            if ($wordLength >= 4 && str_starts_with($token, $word)) return true;
            if (abs($wordLength - $tokenLength) > $maxDistance) continue;
            if (self::distanceWithin($token, $word, $maxDistance)) return true;
        }
        return false;
    }

    private static function distanceWithin(string $a, string $b, int $max): bool
    {
        if ($max < 1 || $a === $b) return $a === $b;
        if (abs(strlen($a) - strlen($b)) > $max) return false;
        if (levenshtein($a, $b) <= $max) return true;
        $length = mb_strlen($a, 'UTF-8');
        if ($length !== mb_strlen($b, 'UTF-8')) return false;
        $left = mb_str_split($a);
        $right = mb_str_split($b);
        $diffs = [];
        for ($i = 0; $i < $length; $i++) {
            if ($left[$i] !== $right[$i]) $diffs[] = $i;
            if (count($diffs) > 2) return false;
        }
        return count($diffs) === 2
            && $diffs[1] === $diffs[0] + 1
            && $left[$diffs[0]] === $right[$diffs[1]]
            && $left[$diffs[1]] === $right[$diffs[0]];
    }
}
