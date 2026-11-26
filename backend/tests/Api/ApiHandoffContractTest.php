<?php

declare(strict_types=1);

namespace Tests\Api;

use Tests\Support\ApiAssertions;
use Tests\Support\ApiFixtures;
use PHPUnit\Framework\TestCase;

final class ApiHandoffContractTest extends TestCase
{
    public function testFixtureIsDeterministicAndEnvelopeRulesAreExecutable(): void
    {
        self::assertSame(ApiFixtures::customer(), ApiFixtures::customer());
        ApiAssertions::successEnvelope(['success' => true, 'message' => 'OK', 'data' => [], 'meta' => ['timestamp' => '2026-01-01T00:00:00Z']]);
        ApiAssertions::errorEnvelope(['success' => false, 'message' => 'Invalid', 'meta' => ['timestamp' => '2026-01-01T00:00:00Z', 'error_code' => 'VALIDATION_ERROR']]);
        self::assertSame('Idempotency-Key', ApiFixtures::idempotencyHeader()['name']);
    }
}
