<?php
declare(strict_types=1);
namespace Tests\Workflow;
use PHPUnit\Framework\TestCase;
final class BookingErpWorkflowTest extends TestCase
{
    public function testBookingErpWorkflowKeepsContracts(): void
    {
        $routes = (string) file_get_contents(dirname(__DIR__, 2) . '/routes/api.php');
        // booking
        self::assertStringContainsString("'/api/admin/products/{id}/booking-slots'", $routes);
        self::assertStringContainsString("'/api/v2/products/{id}/booking-quote'", $routes);
        self::assertStringContainsString("BookingController", $routes);
        self::assertStringContainsString("BookingSlot", file_get_contents(dirname(__DIR__, 2) . '/app/Repositories/ProductRepository.php'));
        // erp lite
        self::assertStringContainsString("'/api/webhooks/erp/loyalty'", $routes);
        self::assertStringContainsString("'/api/webhooks/erp/subscription'", $routes);
        self::assertStringContainsString("'/api/v2/admin/erp/loyalty-evaluate'", $routes);
        self::assertStringContainsString("ErpLiteClient", file_get_contents(dirname(__DIR__, 2) . '/app/Services/ErpLiteClient.php'));
        self::assertStringContainsString("hash_hmac", file_get_contents(dirname(__DIR__, 2) . '/app/Services/ErpLiteClient.php'));
        self::assertStringContainsString("erp_webhook_idempotency", file_get_contents(dirname(__DIR__, 2) . '/database/migrations/20261008000001_create_erp_webhook_idempotency.php'));
        // cart slot
        self::assertStringContainsString("slot_start", file_get_contents(dirname(__DIR__, 2) . '/app/Modules/Cart/Application/CartService.php'));
        // product types
        self::assertStringContainsString("'booking'", file_get_contents(dirname(__DIR__, 2) . '/app/Modules/Catalog/Domain/ProductTypeDefinition.php'));
    }
}
