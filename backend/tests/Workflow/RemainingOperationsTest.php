<?php

declare(strict_types=1);

namespace Tests\Workflow;

use PHPUnit\Framework\TestCase;

final class RemainingOperationsTest extends TestCase
{
    public function testLocalSafetyMarkersArePresent(): void
    {
        $service = (string) file_get_contents(dirname(__DIR__, 2) . '/app/Modules/Payment/Application/PaymentReconciliationService.php');
        self::assertStringContainsString('provider_operation_skipped', $service);
        $repository = (string) file_get_contents(dirname(__DIR__, 2) . '/app/Repositories/OrderRepository.php');
        self::assertStringContainsString('lockForUpdate', $repository);
    }
}
