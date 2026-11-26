<?php
declare(strict_types=1);

namespace App\Modules\Experimentation\Domain;

use App\Support\CommerceScope;

final class ExperimentResolver
{
    public const MAX_EXPERIMENTS = 50;
    public const MAX_VARIANTS = 8;
    public const MAX_CONFIG_BYTES = 32768;

    /** @param mixed $value @return list<array{id:string,enabled:bool,variants:list<array{id:string,weight:int}>}> */
    public static function sanitize(mixed $value): array
    {
        if (!is_array($value) || count($value) > self::MAX_EXPERIMENTS) return [];
        $out = [];
        foreach ($value as $item) {
            if (!is_array($item) || !is_string($item['id'] ?? null) || preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/', $item['id']) !== 1 || ($item['enabled'] ?? true) !== true || !is_array($item['variants'] ?? null)) continue;
            $variants = [];
            foreach ($item['variants'] as $variant) {
                $weight = $variant['weight'] ?? null;
                if (is_array($variant) && is_string($variant['id'] ?? null) && preg_match('/^[a-z0-9][a-z0-9_-]{0,31}$/', $variant['id']) === 1 && is_int($weight) && $weight > 0 && $weight <= 10000) $variants[] = ['id' => $variant['id'], 'weight' => $weight];
            }
            if ($variants && count($variants) <= self::MAX_VARIANTS && array_sum(array_column($variants, 'weight')) <= 10000) $out[] = ['id' => $item['id'], 'enabled' => ($item['enabled'] ?? true) === true, 'variants' => $variants];
        }
        try { return strlen(json_encode($out, JSON_THROW_ON_ERROR)) <= self::MAX_CONFIG_BYTES ? $out : []; } catch (\JsonException) { return []; }
    }

    /** @param list<array{id:string,enabled:bool,variants:list<array{id:string,weight:int}>}> $experiments @return array<string,string> */
    public static function assign(array $experiments, CommerceScope $scope, ?string $subject, bool $consent): array
    {
        $result = [];
        foreach ($experiments as $experiment) {
            $control = $experiment['variants'][0]['id'];
            if (!$experiment['enabled']) continue;
            if (!$consent || $subject === null || $subject === '') { $result[$experiment['id']] = $control; continue; }
            $bucket = hexdec(substr(hash('sha256', $experiment['id'] . '|' . $scope->key() . '|' . $subject), 0, 8)) % 10000;
            $cursor = 0; $selected = $control;
            foreach ($experiment['variants'] as $variant) { $cursor += $variant['weight']; if ($bucket < $cursor) { $selected = $variant['id']; break; } }
            $result[$experiment['id']] = $selected;
        }
        return $result;
    }
}
