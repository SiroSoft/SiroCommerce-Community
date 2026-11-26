<?php

declare(strict_types=1);

namespace Tests\Support;

use PHPUnit\Framework\Assert;

final class ApiAssertions
{
    public static function successEnvelope(array $body): void
    {
        Assert::assertTrue($body['success'] ?? false);
        Assert::assertIsString($body['message'] ?? null);
        Assert::assertArrayHasKey('data', $body);
        Assert::assertIsArray($body['meta'] ?? null);
    }

    public static function errorEnvelope(array $body): void
    {
        Assert::assertFalse($body['success'] ?? true);
        Assert::assertIsString($body['message'] ?? null);
        Assert::assertIsArray($body['meta'] ?? null);
        Assert::assertArrayHasKey('timestamp', $body['meta']);
        Assert::assertArrayHasKey('error_code', $body['meta']);
    }
}
