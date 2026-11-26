<?php

declare(strict_types=1);

namespace App\Modules\Integration\Application;

use App\Modules\Integration\Domain\EndpointPolicy;

final class EndpointRegistration
{
    /**
     * @param array<int,string> $events
     * @return array{scope_key:string,url:string,events:array<int,string>,status:string}
     */
    public static function create(string $scopeKey, string $url, array $events = []): array
    {
        EndpointPolicy::assertSafeUrl($url);
        if ($scopeKey === '') throw new \InvalidArgumentException('Endpoint scope is required.');
        return ['scope_key' => $scopeKey, 'url' => $url, 'events' => array_values(array_unique($events)), 'status' => 'active'];
    }

    /** @return array{secret:string,secret_hash:string,rotated_at:string} */
    public static function rotateSecret(): array
    {
        $secret = bin2hex(random_bytes(32));
        return ['secret' => $secret, 'secret_hash' => hash('sha256', $secret), 'rotated_at' => date('Y-m-d H:i:s')];
    }
}
