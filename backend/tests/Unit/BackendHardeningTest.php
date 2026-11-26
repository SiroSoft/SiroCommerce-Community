<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\PaginationBounds;
use PHPUnit\Framework\TestCase;

final class BackendHardeningTest extends TestCase
{
    public function testPaginationBoundsAreStableForRepositoryCallers(): void
    {
        self::assertSame(1, PaginationBounds::page(0));
        self::assertSame(PaginationBounds::MAX_PAGE, PaginationBounds::page(PHP_INT_MAX));
        self::assertSame(1, PaginationBounds::perPage(0));
        self::assertSame(100, PaginationBounds::perPage(PHP_INT_MAX));
    }

    public function testQueryBudgetMigrationDeclaresCatalogOrderAndQueueIndexes(): void
    {
        $migration = file_get_contents(dirname(__DIR__, 2) . '/database/migrations/20260925000001_backend_query_budgets.php');
        self::assertIsString($migration);
        foreach (['idx_products_browse_scope', 'idx_orders_customer_created', 'idx_orders_scope_created', 'idx_orders_state_created', 'idx_commerce_jobs_ready'] as $index) {
            self::assertStringContainsString($index, $migration);
        }
    }
}
