<?php
declare(strict_types=1);

namespace Tests\Integration;

use App\Models\CreditMemoLine;
use App\Models\InvoiceLine;
use Siro\Core\Database;
use Tests\TestCase;

final class FinanceImmutableLineTest extends TestCase
{
    #[\PHPUnit\Framework\Attributes\DataProvider('lineModels')]
    public function testPersistedEvidenceLineCannotBeUpdatedOrDeleted(string $class, string $table): void
    {
        $this->insertLine($table, 'line-1');
        $line = $class::find('line-1');
        self::assertNotNull($line);

        $this->expectException(\RuntimeException::class);
        $line->setAttribute('name', 'changed');
        $line->save();
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('lineModels')]
    public function testPersistedEvidenceLineCannotBeDeletedThroughTheModel(string $class, string $table): void
    {
        $this->insertLine($table, 'line-2');
        $line = $class::find('line-2');
        self::assertNotNull($line);

        $this->expectException(\RuntimeException::class);
        $line->delete();
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('lineModels')]
    public function testDatabaseGuardBlocksBulkMutationPaths(string $class, string $table): void
    {
        $this->insertLine($table, 'line-3');
        $this->expectException(\PDOException::class);
        Database::connection()->exec("UPDATE {$table} SET name = 'changed' WHERE id = 'line-3'");
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('lineModels')]
    public function testDatabaseGuardBlocksDirectDeletes(string $class, string $table): void
    {
        $this->insertLine($table, 'line-4');
        $this->expectException(\PDOException::class);
        Database::connection()->exec("DELETE FROM {$table} WHERE id = 'line-4'");
    }

    /** @return array<string, array{class: class-string, table: string}> */
    public static function lineModels(): array
    {
        return [
            'invoice' => [InvoiceLine::class, 'invoice_lines'],
            'credit memo' => [CreditMemoLine::class, 'credit_memo_lines'],
        ];
    }

    private function insertLine(string $table, string $id): void
    {
        $columns = $table === 'invoice_lines'
            ? '(id, invoice_id, sku, name, qty, unit_price_minor, amount_minor, tax_minor)'
            : '(id, credit_memo_id, sku, name, amount_minor, tax_minor)';
        $values = $table === 'invoice_lines'
            ? [$id, 'parent-1', 'SKU-1', 'Original', 1, 100, 100, 0]
            : [$id, 'parent-1', 'SKU-1', 'Original', 100, 0];
        $marks = implode(',', array_fill(0, count($values), '?'));
        $statement = Database::connection()->prepare("INSERT INTO {$table} {$columns} VALUES ({$marks})");
        $statement->execute($values);
    }
}
