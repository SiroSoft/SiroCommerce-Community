<?php

declare(strict_types=1);

namespace Tests\Integration;

use Tests\TestCase;

final class MySqlConcurrencyProofTest extends TestCase
{
    protected function setUp(): void
    {
        if (strtolower((string) (getenv('TEST_DB_DRIVER') ?: 'sqlite')) !== 'mysql') {
            self::markTestSkipped('MySQL 8 proof requires TEST_DB_DRIVER=mysql; SQLite is not evidence.');
        }
        parent::setUp();
    }

    public function testSeparateConnectionV7ProofRunner(): void
    {
        $output = [];
        $status = 1;
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__, 2) . '/scripts/mysql-concurrency-proof.php'), $output, $status);
        self::assertSame(0, $status, implode(PHP_EOL, $output));
        $evidence = json_decode(implode(PHP_EOL, $output), true);
        self::assertIsArray($evidence);
        self::assertSame('PASS', $evidence['status'] ?? null);
        self::assertSame(2, $evidence['connections']['expected'] ?? null);
        self::assertTrue($evidence['connections']['verified'] ?? false);
        self::assertTrue($evidence['preflight']['passed'] ?? false);
    }
}
