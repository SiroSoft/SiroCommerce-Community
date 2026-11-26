<?php

declare(strict_types=1);

namespace App\Modules\Integration\Domain;

final class EndpointPolicy
{
    public static function assertIsolated(string $endpointScope, string $eventScope): void
    {
        if ($endpointScope === '' || $eventScope === '' || !hash_equals($endpointScope, $eventScope)) {
            throw new \RuntimeException('Integration endpoint is outside the event scope.');
        }
    }

    public static function assertSafeUrl(string $url): void
    {
        $parts = parse_url($url);
        $host = is_array($parts) ? strtolower((string) ($parts['host'] ?? '')) : '';
        if (($parts['scheme'] ?? '') !== 'https' || $host === '' || isset($parts['user']) || isset($parts['pass'])) {
            throw new \InvalidArgumentException('Integration endpoints must use an HTTPS URL without embedded credentials.');
        }
        if ($host === 'localhost' || $host === '::1' || filter_var($host, FILTER_VALIDATE_IP) !== false && filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            throw new \InvalidArgumentException('Integration endpoint host is not allowed.');
        }
    }
}
