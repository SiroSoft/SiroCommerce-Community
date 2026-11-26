<?php

declare(strict_types=1);

namespace App\Modules\Integration\Domain;

final class IntegrationKey
{
    /** @return array{public:string,secret:string,hash:string} */
    public static function issue(string $prefix = 'sc_live_'): array
    {
        $public = $prefix . bin2hex(random_bytes(8));
        $secret = bin2hex(random_bytes(32));
        return ['public' => $public, 'secret' => $secret, 'hash' => self::hash($secret)];
    }

    public static function hash(string $secret): string
    {
        if ($secret === '') throw new \InvalidArgumentException('Integration key secret is required.');
        return hash('sha256', $secret);
    }

    public static function verify(string $secret, string $storedHash): bool
    {
        return $secret !== '' && preg_match('/^[a-f0-9]{64}$/', $storedHash) === 1 && hash_equals($storedHash, self::hash($secret));
    }
}
