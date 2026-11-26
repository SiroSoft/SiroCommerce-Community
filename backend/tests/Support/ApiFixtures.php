<?php

declare(strict_types=1);

namespace Tests\Support;

final class ApiFixtures
{
    public static function customer(): array
    {
        return ['id' => 'cus_test_0001', 'email' => 'api1@example.test', 'locale' => 'en', 'currency' => 'USD'];
    }

    public static function idempotencyHeader(): array
    {
        return ['name' => 'Idempotency-Key', 'value' => 'api1-test-checkout-0001'];
    }
}
