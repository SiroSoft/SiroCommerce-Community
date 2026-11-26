<?php
declare(strict_types=1);
namespace Tests\Unit;
use App\Modules\Platform\Infrastructure\BiExportService;
use App\Models\BiExport;
use App\Models\Order;
use App\Role;
use App\Resources\BiExportResource;
use Siro\Core\Queue;
use Siro\Core\Storage;
use Siro\Core\Database;
use Siro\Core\Schema;
use App\Support\Ids;
use Tests\TestCase;
final class BiExportContractTest extends TestCase
{
    public function testExportCapabilityIsRestrictedToDataStaff(): void
    {
        self::assertTrue(Role::can(Role::ADMIN, 'exports')); self::assertTrue(Role::can(Role::FINANCE, 'exports'));
        self::assertTrue(Role::can(Role::OWNER, 'exports')); self::assertFalse(Role::can(Role::MARKETER, 'exports')); self::assertFalse(Role::can(Role::VIEWER, 'exports'));
    }
    public function testDatasetAndWindowAreBounded(): void
    {
        $service = new BiExportService(); $this->expectException(\InvalidArgumentException::class); $service->create('orders', 'jsonl', '2020-01-01', '2026-01-01');
    }
    public function testUnsupportedFormatIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class); (new BiExportService())->create('orders', 'xml', null, null);
    }

    public function testAllPersistedDatasetsCanBeReadWithTheirActualSchemas(): void
    {
        Queue::fake(); Storage::fake();
        $service = new BiExportService();
        foreach (['orders', 'payments', 'refunds', 'subscriptions', 'b2b', 'promotions', 'fulfillment', 'inventory'] as $dataset) {
            $export = $service->create($dataset, 'jsonl', date('Y-m-d'), date('Y-m-d'));
            $service->process((string) $export['id']);
            self::assertSame('completed', $service->status((string) $export['id'])['state'], $dataset);
            self::assertTrue(Storage::exists('exports/' . $export['id'] . '.jsonl'), $dataset);
        }
        Storage::reset();
    }

    public function testQueuedExportIsSnapshotScopedAndRedacted(): void
    {
        Queue::fake(); $id = Ids::new();
        $order = Order::create(['id' => $id, 'number' => 'SC-EXPORT', 'guest_email' => 'private@example.test', 'currency' => 'VND', 'subtotal_minor' => 100, 'grand_total_minor' => 100, 'state' => 'paid', 'idempotency_key' => Ids::new()]); $order->setAttribute('created_at', date('Y-m-d H:i:s')); $order->save();
        self::assertSame(1, Order::query()->count());
        $service = new BiExportService(); $metadata = $service->create('orders', 'jsonl', date('Y-m-d', time() - 172800), date('Y-m-d', time() + 86400));
        self::assertSame('queued', $metadata['state']); $service->process((string) $metadata['id']);
        $completed = $service->status((string) $metadata['id']); self::assertSame('completed', $completed['state']); self::assertArrayNotHasKey('storage_path', $completed);
        self::assertNotEmpty($completed['download_url']); self::assertArrayNotHasKey('last_error', $completed);
        self::assertArrayNotHasKey('guest_email', BiExportResource::make(['id' => 'x', 'guest_email' => 'private@example.test']));
    }

    public function testFailedExportCanBeReplayed(): void
    {
        Queue::fake(); $id = Ids::new(); $now = date('Y-m-d H:i:s');
        BiExport::create(['id' => $id, 'dataset' => 'orders', 'format' => 'jsonl', 'schema_version' => 1, 'from_at' => $now, 'to_at' => $now, 'snapshot_at' => $now, 'state' => 'failed', 'rows_written' => 0, 'batch_size' => 100, 'created_at' => $now, 'updated_at' => $now]);
        self::assertSame('queued', (new BiExportService())->retry($id)['state']);
    }

    public function testExactlyOneBatchPersistsCheckpointBeforeSchedulingNextBatch(): void
    {
        Queue::fake(); Storage::fake(); $now = date('Y-m-d H:i:s');
        $insert = Database::connection()->prepare('INSERT INTO orders (id, number, guest_email, currency, subtotal_minor, grand_total_minor, state, idempotency_key, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
        for ($i = 1; $i <= 101; $i++) {
            $id = str_pad((string) $i, 26, '0', STR_PAD_LEFT);
            $insert->execute([$id, 'B-' . $i, 'private@example.test', 'VND', 100, 100, 'paid', 'key-' . $i, $now]);
        }
        $service = new BiExportService(); $metadata = $service->create('orders', 'jsonl', date('Y-m-d'), date('Y-m-d'));
        $service->process((string) $metadata['id']);
        $checkpoint = $service->status((string) $metadata['id']);
        self::assertSame('queued', $checkpoint['state']); self::assertSame(100, $checkpoint['rows_written']);
        self::assertNotNull($checkpoint['cursor_at']); self::assertNotNull($checkpoint['cursor_id']);
        Queue::assertPushed(\App\Jobs\BiExportJob::class);
        $service->process((string) $metadata['id']);
        $completed = $service->status((string) $metadata['id']);
        self::assertSame('completed', $completed['state']); self::assertSame(101, $completed['rows_written']);
        $content = Storage::get('exports/' . $metadata['id'] . '.jsonl');
        self::assertIsString($content); self::assertCount(101, array_filter(explode("\n", $content)));
        self::assertStringNotContainsString('private@example.test', $content);
        Storage::reset();
    }

    public function testJsonlAndCsvAreUtf8SafeAndRedacted(): void
    {
        Queue::fake(); Storage::fake(); $now = date('Y-m-d H:i:s');
        $orderId = Ids::new();
        Database::connection()->prepare('INSERT INTO orders (id, number, guest_email, currency, subtotal_minor, grand_total_minor, state, idempotency_key, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute([$orderId, 'UTF-8', 'secret@example.test', 'VND', 100, 100, 'paid', Ids::new(), $now]);
        $service = new BiExportService();
        foreach (['jsonl', 'csv'] as $format) {
            $export = $service->create('orders', $format, date('Y-m-d'), date('Y-m-d')); $service->process((string) $export['id']);
            self::assertSame(1, $service->status((string) $export['id'])['rows_written']);
            $content = Storage::get('exports/' . $export['id'] . '.' . $format);
             self::assertIsString($content); self::assertTrue(mb_check_encoding($content, 'UTF-8'));
             self::assertStringNotContainsString('secret@example.test', $content);
             if ($format === 'jsonl') self::assertStringContainsString('"schema_version":1', $content);
             else { self::assertStringContainsString('"number"', $content); self::assertStringContainsString('"100"', $content); }
        }
        Storage::reset();
    }

    public function testUnsupportedStoredSchemaCannotBeProcessed(): void
    {
        $id = Ids::new(); $now = date('Y-m-d H:i:s');
        BiExport::create(['id' => $id, 'dataset' => 'orders', 'format' => 'jsonl', 'schema_version' => 99, 'from_at' => $now, 'to_at' => $now, 'snapshot_at' => $now, 'state' => 'queued', 'rows_written' => 0, 'batch_size' => 100, 'created_at' => $now, 'updated_at' => $now]);
        $this->expectException(\InvalidArgumentException::class);
        (new BiExportService())->process($id);
    }

    public function testRetryRemovesOnlyAnUncheckpointedPartialFile(): void
    {
        Queue::fake(); Storage::fake(); $id = Ids::new(); $now = date('Y-m-d H:i:s');
        $path = 'exports/' . $id . '.jsonl'; Storage::put($path, 'partial');
        BiExport::create(['id' => $id, 'dataset' => 'orders', 'format' => 'jsonl', 'schema_version' => 1, 'from_at' => $now, 'to_at' => $now, 'snapshot_at' => $now, 'state' => 'failed', 'rows_written' => 0, 'batch_size' => 100, 'storage_path' => $path, 'created_at' => $now, 'updated_at' => $now]);
        (new BiExportService())->retry($id);
        Storage::assertMissing($path); Storage::reset();
    }

    public function testRunningExportIsNotProcessedTwice(): void
    {
        Storage::fake(); $id = Ids::new(); $now = date('Y-m-d H:i:s');
        BiExport::create(['id' => $id, 'dataset' => 'orders', 'format' => 'jsonl', 'schema_version' => 1, 'from_at' => $now, 'to_at' => $now, 'snapshot_at' => $now, 'state' => 'running', 'rows_written' => 0, 'batch_size' => 100, 'created_at' => $now, 'updated_at' => $now]);
        (new BiExportService())->process($id);
        Storage::assertMissing('exports/' . $id . '.jsonl'); Storage::reset();
    }

    public function testDownloadMetadataRequiresCompletion(): void
    {
        $id = Ids::new(); $now = date('Y-m-d H:i:s');
        BiExport::create(['id' => $id, 'dataset' => 'orders', 'format' => 'csv', 'schema_version' => 1, 'from_at' => $now, 'to_at' => $now, 'snapshot_at' => $now, 'state' => 'queued', 'rows_written' => 0, 'batch_size' => 100, 'created_at' => $now, 'updated_at' => $now]);
        $this->expectException(\InvalidArgumentException::class);
        (new BiExportService())->downloadMetadata($id);
    }

    public function testExportMigrationRollsBackAndCanBeReapplied(): void
    {
        $migration = require dirname(__DIR__, 2) . '/database/migrations/20260920000027_create_bi_exports.php';
        $migration->down();
        $driver = Database::connection()->getAttribute(\PDO::ATTR_DRIVER_NAME);
        $tableExists = static function () use ($driver): bool {
            $pdo = Database::connection();
            if ($driver === 'mysql') return (bool) $pdo->query("SHOW TABLES LIKE 'bi_exports'")->fetchColumn();
            return (bool) $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name = 'bi_exports'")->fetchColumn();
        };
        self::assertFalse($tableExists());
        $migration->up();
        // Restore the dependent columns after recreating the table. The parent
        // reporting tables remain intact in the shared MySQL schema.
        Schema::table('bi_exports', static function ($table): void {
            $table->string('store_id', 26)->nullable();
            $table->string('channel_id', 26)->nullable();
        });
        self::assertTrue($tableExists());
    }
}
