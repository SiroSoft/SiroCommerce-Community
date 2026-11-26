<?php
declare(strict_types=1);
namespace Tests\Unit;
use App\Modules\Catalog\Application\CatalogCompletenessService;
use App\Modules\Catalog\Application\CatalogTransferService;
use App\Modules\Platform\Infrastructure\CatalogAdminAdapter;
use App\Modules\Platform\Infrastructure\CatalogImportStoreAdapter;
use App\Repositories\ProductRepository;
use Tests\TestCase;
use Siro\Core\Database;
final class CatalogTransferContractTest extends TestCase
{
    private function service(): CatalogTransferService
    {
        return new CatalogTransferService(
            new CatalogCompletenessService(new CatalogAdminAdapter(new ProductRepository())),
            new CatalogImportStoreAdapter(),
        );
    }

    public function testValidateOnlyIsVersionedAndReportsRowsWithoutWriting(): void
    {
        $result = $this->service()->import('jsonl', "{\"schema_version\":1,\"record\":{\"name\":\"Valid\",\"slug\":\"valid\"}}\n{\"schema_version\":99,\"record\":{\"name\":\"Bad\"}}\n", true);
        self::assertSame(2, $result['rows_seen']);
        self::assertCount(1, $result['errors']);
        self::assertTrue($result['validate_only']);
        self::assertSame(0, (int) Database::connection()->query('SELECT COUNT(*) FROM products')->fetchColumn());
    }

    public function testImportFingerprintMakesReplayDeterministicAndCsvIsBounded(): void
    {
        $source = "name,slug,status\nOne,one,draft\nTwo,two,active\n";
        $first = $this->service()->import('csv', $source);
        $second = $this->service()->import('csv', $source);
        self::assertSame(2, $first['rows_imported']);
        self::assertTrue($second['replayed']);
        self::assertSame($first['source_fingerprint'], $second['source_fingerprint']);
        self::assertSame(2, (int) Database::connection()->query('SELECT COUNT(*) FROM products')->fetchColumn());
    }

    public function testExportUsesTheVersionedTransferSchema(): void
    {
        $service = $this->service();
        $service->import('jsonl', "{\"schema_version\":1,\"record\":{\"name\":\"Exportable\",\"slug\":\"exportable\"}}\n");

        $export = $service->export('jsonl');
        $record = json_decode(trim($export), true);
        self::assertIsArray($record);
        self::assertSame(1, $record['schema_version']);
        self::assertSame('exportable', $record['record']['slug']);
    }
}
