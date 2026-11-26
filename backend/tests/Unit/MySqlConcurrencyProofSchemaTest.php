<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/scripts/mysql-concurrency-proof-schema.php';

final class MySqlConcurrencyProofSchemaTest extends TestCase
{
    public function testPassEvidenceRequiresPreflightTwoSessionsAndAllInventoryProofs(): void
    {
        $evidence = ['manifest_version' => 1, 'proof' => 'mysql8-atp-reservation-transfer', 'status' => 'PASS', 'connections' => ['expected' => 2, 'verified' => true], 'preflight' => ['passed' => true], 'checks' => ['atp_lock_wait' => true, 'atp_guarded_reservation' => true, 'reservation_idempotency' => true, 'transfer_idempotency_and_conservation' => true]];
        self::assertSame([], mysqlProofEvidenceErrors($evidence));
    }

    public function testPassEvidenceIsRejectedWhenAProofCheckIsMissing(): void
    {
        $evidence = ['manifest_version' => 1, 'proof' => 'mysql8-atp-reservation-transfer', 'status' => 'PASS', 'connections' => ['expected' => 2, 'verified' => true], 'preflight' => ['passed' => true], 'checks' => ['atp_guarded_reservation' => true]];
        self::assertNotEmpty(mysqlProofEvidenceErrors($evidence));
    }

    public function testPassEvidenceIsRejectedWithoutObservedLockContention(): void
    {
        $evidence = ['manifest_version' => 1, 'proof' => 'mysql8-atp-reservation-transfer', 'status' => 'PASS', 'connections' => ['expected' => 2, 'verified' => true], 'preflight' => ['passed' => true], 'checks' => ['atp_guarded_reservation' => true, 'reservation_idempotency' => true, 'transfer_idempotency_and_conservation' => true]];
        self::assertNotEmpty(mysqlProofEvidenceErrors($evidence));
    }

    public function testNonMysqlStatusMaySkipWithoutProofFields(): void
    {
        self::assertSame([], mysqlProofEvidenceErrors(['manifest_version' => 1, 'proof' => 'mysql8-atp-reservation-transfer', 'status' => 'SKIP', 'connections' => ['expected' => 2, 'verified' => false]]));
    }

    public function testPreflightCannotPassWithAFalseCheck(): void
    {
        self::assertFalse(mysqlProofAllTrue(['mysql8' => true, 'innodb' => false, 'isolation' => true]));
        self::assertTrue(mysqlProofAllTrue(['mysql8' => true, 'innodb' => true, 'isolation' => true]));
    }
}
