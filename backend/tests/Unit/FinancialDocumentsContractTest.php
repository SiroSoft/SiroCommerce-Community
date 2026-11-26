<?php
declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class FinancialDocumentsContractTest extends TestCase
{
    public function test_customer_finance_routes_are_authenticated_and_admin_reconciliation_is_finance_scoped(): void
    {
        $routes = file_get_contents(__DIR__ . '/../../routes/api.php');
        self::assertIsString($routes);
        self::assertStringContainsString("/api/account/orders/{id}/financial-documents", $routes);
        self::assertStringContainsString("array_merge(\$fe, ['auth'])", substr($routes, strpos($routes, "'/api/account/orders/{id}/financial-documents"), 180));
        self::assertStringContainsString("/api/v2/admin/finance/reconciliation", $routes);
        self::assertStringContainsString("auth:admin,owner,finance", substr($routes, strpos($routes, "'/api/v2/admin/finance/reconciliation"), 220));
    }

    public function test_customer_document_query_does_not_expose_provider_evidence(): void
    {
        $source = file_get_contents(__DIR__ . '/../../app/Modules/Finance/Application/FinancialDocumentQueryService.php');
        self::assertIsString($source);
        self::assertStringNotContainsString('evidence_json', $source);
        // Ownership stays scoped to the account's order identities (profile
        // id + member user id) — IN over the identity set, never unscoped.
        self::assertStringContainsString("whereIn('customer_id',", $source);
        self::assertStringNotContainsString("where('customer_id',", $source);
    }
}
