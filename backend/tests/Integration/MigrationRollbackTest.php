<?php

declare(strict_types=1);

namespace Tests\Integration;

use Siro\Core\Database;
use Tests\TestCase;

final class MigrationRollbackTest extends TestCase
{
    public static function tearDownAfterClass(): void
    {
        self::markMySqlSchemaDirty();
        parent::tearDownAfterClass();
    }

    public function testReturnMigrationsRollBackAddedSchema(): void
    {
        $completionEvidence = require dirname(__DIR__, 2) . '/database/migrations/20260920000018_add_rma_completion_evidence.php';
        $returnEnrichment = require dirname(__DIR__, 2) . '/database/migrations/20260920000015_enrich_return_requests.php';

        $completionEvidence->down();
        $returnEnrichment->down();

        $pdo = Database::connection();
        $driver = (string) $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
        if ($driver === 'sqlite') {
            self::assertSame(0, (int) $pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name IN ('return_events', 'return_inventory_postings')")->fetchColumn());
            $columns = $pdo->query("PRAGMA table_info('return_requests')")->fetchAll(\PDO::FETCH_ASSOC);
            $indexes = $pdo->query("PRAGMA index_list('return_requests')")->fetchAll(\PDO::FETCH_ASSOC);
        } else {
            self::assertSame(0, (int) $pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name IN ('return_events', 'return_inventory_postings')")->fetchColumn());
            $columns = $pdo->query('SHOW COLUMNS FROM return_requests')->fetchAll(\PDO::FETCH_ASSOC);
            $indexes = $pdo->query('SHOW INDEX FROM return_requests')->fetchAll(\PDO::FETCH_ASSOC);
        }
        $columnNames = array_column($columns, $driver === 'sqlite' ? 'name' : 'Field');
        self::assertNotContains('label_idempotency_key', $columnNames);
        self::assertNotContains('label_state', $columnNames);
        self::assertNotContains('disposition', $columnNames);
        self::assertNotContains('inspection_note', $columnNames);

        $indexNames = array_column($indexes, $driver === 'sqlite' ? 'name' : 'Key_name');
        self::assertNotContains('uq_return_requests_label_idempotency_key', $indexNames);
    }

    public function testAnalyticsEvidenceMigrationRollsBackCleanly(): void
    {
        $migration = require dirname(__DIR__, 2) . '/database/migrations/20260920000026_extend_analytics_evidence.php';
        $migration->down();

        $pdo = Database::connection();
        $driver = (string) $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
        if ($driver === 'sqlite') {
            $columns = $pdo->query("PRAGMA table_info('analytics_events')")->fetchAll(\PDO::FETCH_ASSOC);
            $indexes = $pdo->query("PRAGMA index_list('analytics_events')")->fetchAll(\PDO::FETCH_ASSOC);
        } else {
            $columns = $pdo->query('SHOW COLUMNS FROM analytics_events')->fetchAll(\PDO::FETCH_ASSOC);
            $indexes = $pdo->query('SHOW INDEX FROM analytics_events')->fetchAll(\PDO::FETCH_ASSOC);
        }
        $columnNames = array_column($columns, $driver === 'sqlite' ? 'name' : 'Field');
        $indexNames = array_column($indexes, $driver === 'sqlite' ? 'name' : 'Key_name');
        self::assertNotContains('event_id', $columnNames);
        self::assertNotContains('schema_version', $columnNames);
        self::assertNotContains('payload_json', $columnNames);
        self::assertNotContains('uq_analytics_event_id', $indexNames);
        self::assertNotContains('idx_analytics_created_event', $indexNames);
    }

    public function testCustomerEngagementMigrationRollsBackAllWorkstreamTables(): void
    {
        $migration = require dirname(__DIR__, 2) . '/database/migrations/20260920000030_create_customer_preferences_loyalty.php';
        $migration->down();

        $pdo = Database::connection();
        $driver = (string) $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
        if ($driver === 'sqlite') {
            $count = $pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name IN ('customer_notification_preferences', 'loyalty_accounts', 'loyalty_ledger')")->fetchColumn();
        } else {
            $count = $pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name IN ('customer_notification_preferences', 'loyalty_accounts', 'loyalty_ledger')")->fetchColumn();
        }
        self::assertSame(0, (int) $count);
    }

    public function testShippingEnrichmentMigrationRollsBackCleanly(): void
    {
        $migration = require dirname(__DIR__, 2) . '/database/migrations/20260920000024_enrich_shipping_operations.php';
        $migration->down();

        $pdo = Database::connection();
        $driver = (string) $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
        if ($driver === 'sqlite') {
            $shipments = $pdo->query("PRAGMA table_info('shipping_shipments')")->fetchAll(\PDO::FETCH_ASSOC);
            $events = $pdo->query("PRAGMA table_info('shipping_tracking_events')")->fetchAll(\PDO::FETCH_ASSOC);
        } else {
            $shipments = $pdo->query('SHOW COLUMNS FROM shipping_shipments')->fetchAll(\PDO::FETCH_ASSOC);
            $events = $pdo->query('SHOW COLUMNS FROM shipping_tracking_events')->fetchAll(\PDO::FETCH_ASSOC);
        }
        $shipmentNames = array_column($shipments, $driver === 'sqlite' ? 'name' : 'Field');
        $eventNames = array_column($events, $driver === 'sqlite' ? 'name' : 'Field');
        self::assertNotContains('packages_json', $shipmentNames);
        self::assertNotContains('last_error', $shipmentNames);
        self::assertNotContains('attempts', $shipmentNames);
        self::assertNotContains('provider', $eventNames);
        self::assertNotContains('headers_json', $eventNames);
    }

    public function testInventoryAllocationGroupMigrationRollsBackCleanly(): void
    {
        $migration = require dirname(__DIR__, 2) . '/database/migrations/20260921000052_inventory_allocation_groups.php';
        $migration->down();

        $pdo = Database::connection();
        $driver = (string) $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
        if ($driver === 'sqlite') {
            $columns = $pdo->query("PRAGMA table_info('reservations')")->fetchAll(\PDO::FETCH_ASSOC);
            $indexes = $pdo->query("PRAGMA index_list('reservations')")->fetchAll(\PDO::FETCH_ASSOC);
        } else {
            $columns = $pdo->query('SHOW COLUMNS FROM reservations')->fetchAll(\PDO::FETCH_ASSOC);
            $indexes = $pdo->query('SHOW INDEX FROM reservations')->fetchAll(\PDO::FETCH_ASSOC);
        }
        self::assertNotContains('allocation_key', array_column($columns, $driver === 'sqlite' ? 'name' : 'Field'));
        self::assertNotContains('idx_reservations_allocation_key_state', array_column($indexes, $driver === 'sqlite' ? 'name' : 'Key_name'));
    }

    public function testInventoryLedgerReconciliationMigrationRollsBackCleanly(): void
    {
        $migration = require dirname(__DIR__, 2) . '/database/migrations/20260921000055_inventory_ledger_reconciliation.php';
        $migration->down();

        $pdo = Database::connection();
        $driver = (string) $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
        $indexes = $driver === 'sqlite'
            ? $pdo->query("PRAGMA index_list('stock_ledger')")->fetchAll(\PDO::FETCH_ASSOC)
            : $pdo->query('SHOW INDEX FROM stock_ledger')->fetchAll(\PDO::FETCH_ASSOC);
        self::assertNotContains('idx_stock_ledger_variant_id_source_id_created_at', array_column($indexes, $driver === 'sqlite' ? 'name' : 'Key_name'));
    }

    public function testCmsMutationIdempotencyMigrationRollsBackCleanly(): void
    {
        $migration = require dirname(__DIR__, 2) . '/database/migrations/20260921000057_cms_mutation_idempotency.php';
        $migration->down();

        $pdo = Database::connection();
        $driver = (string) $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
        $columns = $driver === 'sqlite'
            ? $pdo->query("PRAGMA table_info('cms_revisions')")->fetchAll(\PDO::FETCH_ASSOC)
            : $pdo->query('SHOW COLUMNS FROM cms_revisions')->fetchAll(\PDO::FETCH_ASSOC);
        self::assertNotContains('idempotency_key', array_column($columns, $driver === 'sqlite' ? 'name' : 'Field'));
    }

    public function testFinanceImmutableLineMigrationRollsBackCleanly(): void
    {
        $migration = require dirname(__DIR__, 2) . '/database/migrations/20260921000058_finance_immutable_line_evidence.php';
        $migration->down();

        $pdo = Database::connection();
        $driver = (string) $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
        $tables = $driver === 'sqlite'
            ? $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table'")->fetchAll(\PDO::FETCH_COLUMN)
            : $pdo->query("SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE()")->fetchAll(\PDO::FETCH_COLUMN);
        self::assertNotContains('invoice_lines', $tables);
        self::assertNotContains('invoice_taxes', $tables);
        self::assertNotContains('credit_memo_lines', $tables);
        self::assertNotContains('credit_memo_taxes', $tables);
    }

    public function testB2bQuoteLifecycleMigrationRollsBackCleanly(): void
    {
        $migration = require dirname(__DIR__, 2) . '/database/migrations/20260921000059_create_b2b_quote_lifecycle.php';
        $migration->down();

        $pdo = Database::connection();
        $driver = (string) $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
        $tables = $driver === 'sqlite'
            ? $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table'")->fetchAll(\PDO::FETCH_COLUMN)
            : $pdo->query("SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE()")->fetchAll(\PDO::FETCH_COLUMN);
        self::assertNotContains('b2b_quote_revisions', $tables);
        self::assertNotContains('b2b_quote_transitions', $tables);
        self::assertNotContains('b2b_quote_credit_reservations', $tables);
    }

    public function testVendorMarketplaceMigrationRollsBackCleanly(): void
    {
        $migration = require dirname(__DIR__, 2) . '/database/migrations/20261120000001_create_vendors.php';
        $migration->down();

        $pdo = Database::connection();
        $driver = (string) $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
        $tables = $driver === 'sqlite'
            ? $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table'")->fetchAll(\PDO::FETCH_COLUMN)
            : $pdo->query("SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE()")->fetchAll(\PDO::FETCH_COLUMN);
        $columns = $driver === 'sqlite'
            ? $pdo->query("PRAGMA table_info('products')")->fetchAll(\PDO::FETCH_ASSOC)
            : $pdo->query('SHOW COLUMNS FROM products')->fetchAll(\PDO::FETCH_ASSOC);
        $indexes = $driver === 'sqlite'
            ? $pdo->query("PRAGMA index_list('products')")->fetchAll(\PDO::FETCH_ASSOC)
            : $pdo->query('SHOW INDEX FROM products')->fetchAll(\PDO::FETCH_ASSOC);

        self::assertNotContains('vendors', $tables);
        self::assertNotContains('vendor_id', array_column($columns, $driver === 'sqlite' ? 'name' : 'Field'));
        self::assertNotContains('idx_products_vendor_id', array_column($indexes, $driver === 'sqlite' ? 'name' : 'Key_name'));
    }
}
