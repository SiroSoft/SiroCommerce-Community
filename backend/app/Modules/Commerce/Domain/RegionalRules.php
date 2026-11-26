<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Domain;

/** Deterministic regional policy shared by checkout, tax, and shipping. */
final class RegionalRules
{
    /** @var array<string, string> */
    private const REGIONS = [
        'VN' => 'VN', 'SG' => 'SG', 'MY' => 'MY', 'TH' => 'TH', 'ID' => 'ID', 'PH' => 'PH',
        'BN' => 'BN', 'KH' => 'KH', 'LA' => 'LA', 'MM' => 'MM', 'DE' => 'DE', 'FR' => 'FR',
        'GB' => 'GB', 'US' => 'US', 'CA' => 'CA', 'AU' => 'AU', 'JP' => 'JP', 'KR' => 'KR',
        'IN' => 'IN', 'CN' => 'CN', 'HK' => 'HK', 'TW' => 'TW', 'AE' => 'AE',
    ];

    /**
     * @param array<string, mixed> $address
     * @return array<string, string>
     */
    public static function address(array $address, string $field = 'destination'): array
    {
        $country = self::value($address, 'country');
        if (!preg_match('/^[A-Z]{2}$/', $country) || !isset(self::REGIONS[$country])) {
            throw new \InvalidArgumentException('Unsupported ' . $field . ' country');
        }
        $region = self::value($address, 'region', ['state', 'province']);
        $postal = self::value($address, 'postal_code', ['postal', 'zip']);
        if ($postal !== '' && !preg_match('/^[A-Z0-9][A-Z0-9 .-]{1,15}$/', $postal)) {
            throw new \InvalidArgumentException('Invalid ' . $field . ' postal code');
        }
        return ['country' => $country, 'region' => $region, 'postal_code' => $postal];
    }

    /** @param array<string, mixed> $origin @param array<string, mixed> $destination @return array<string, mixed> */
    public static function zone(array $origin, array $destination): array
    {
        $from = self::address($origin, 'origin');
        $to = self::address($destination, 'destination');
        $zone = $from['country'] === $to['country'] ? 'domestic' : (self::isRegional($from['country'], $to['country']) ? 'regional' : 'international');
        return ['code' => $zone, 'origin' => $from, 'destination' => $to];
    }

    /** @return array<string, mixed> */
    public static function evidence(array $origin, array $destination): array
    {
        $zone = self::zone($origin, $destination);
        return ['policy' => 'regional-rules-v1', 'zone' => $zone['code'], 'origin' => $zone['origin'], 'destination' => $zone['destination']];
    }

    private static function isRegional(string $from, string $to): bool
    {
        $asean = ['BN', 'KH', 'ID', 'LA', 'MY', 'MM', 'PH', 'SG', 'TH', 'VN'];
        return in_array($from, $asean, true) && in_array($to, $asean, true);
    }

    /** @param array<string, mixed> $value @param array<int, string> $aliases */
    private static function value(array $value, string $key, array $aliases = []): string
    {
        $raw = $value[$key] ?? $value['destination_' . $key] ?? null;
        foreach ($aliases as $alias) if ($raw === null) $raw = $value[$alias] ?? null;
        return is_scalar($raw) ? strtoupper(trim((string) $raw)) : '';
    }
}
