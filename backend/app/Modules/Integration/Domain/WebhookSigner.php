<?php

declare(strict_types=1);

namespace App\Modules\Integration\Domain;

final class WebhookSigner
{
    public static function sign(string $secret, string $body, int $timestamp, string $eventId): string
    {
        if ($secret === '' || $eventId === '') throw new \InvalidArgumentException('Webhook signing inputs are required.');
        return 'v1=' . hash_hmac('sha256', $timestamp . '.' . $eventId . '.' . $body, $secret);
    }

    public static function verify(string $secret, string $body, int $timestamp, string $eventId, string $signature, int $tolerance = 300, ?int $now = null): bool
    {
        if ($secret === '' || $eventId === '' || $signature === '' || abs(($now ?? time()) - $timestamp) > max(1, $tolerance)) return false;
        $expected = self::sign($secret, $body, $timestamp, $eventId);
        return hash_equals($expected, trim($signature));
    }
}
