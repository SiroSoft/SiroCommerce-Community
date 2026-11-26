<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Smart\Application\SmartAggregator;
use Siro\Core\Database;
use Tests\TestCase;

final class SmartAggregatorQueryBudgetTest extends TestCase
{
    /** @param array<int, array{0:string,1:string,2:string}> $lines */
    private function order(string $id, string $state, array $lines): void
    {
        $pdo = Database::connection();
        $pdo->prepare('INSERT INTO orders (id, number, currency, subtotal_minor, grand_total_minor, state, idempotency_key, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$id, strtoupper($id), 'VND', 100, 100, $state, $id . '-key', date('Y-m-d H:i:s')]);
        foreach ($lines as $index => [$variantId, $sku, $name]) {
            $pdo->prepare('INSERT INTO order_lines (id, order_id, variant_id, sku, name, qty, unit_price_minor) VALUES (?, ?, ?, ?, ?, ?, ?)')
                ->execute([$id . '-line-' . $index, $id, $variantId, $sku, $name, 1, 100]);
        }
    }

    private function capturedSql(string $needle): int
    {
        $count = 0;
        foreach (Database::getCapturedQueries() as $query) {
            if (isset($query['sql']) && is_string($query['sql']) && str_contains($query['sql'], $needle)) $count++;
        }
        return $count;
    }

    public function testAffinitiesUseOneOrderLineReadRegardlessOfOrderCount(): void
    {
        foreach (range(1, 6) as $i) {
            $this->order('aff-' . $i, 'paid', [['aff-v-a', 'A', 'A'], ['aff-v-b', 'B', 'B']]);
        }
        $this->order('aff-cancelled', 'cancelled', [['aff-v-a', 'A', 'A'], ['aff-v-c', 'C', 'C']]);

        Database::enableQueryCapture();
        Database::resetCapturedQueries();
        $written = (new SmartAggregator())->aggregateAffinities();

        self::assertSame(1, $written);
        self::assertSame(1, $this->capturedSql('FROM order_lines l JOIN orders o'));
        self::assertSame(0, $this->capturedSql('FROM order_lines WHERE order_id = ?'));
        $row = Database::connection()->query("SELECT product_a, product_b, orders FROM smart_affinities")->fetch(\PDO::FETCH_ASSOC);
        self::assertIsArray($row);
        self::assertSame(6, (int) $row['orders']);
    }

    public function testReplenishmentMapsVariantsInOneQuery(): void
    {
        $pdo = Database::connection();
        $orderLines = [];
        foreach (range(1, 5) as $i) {
            $variantId = 'rep-v-' . $i;
            $pdo->prepare('INSERT INTO product_variants (id, product_id, sku, price_minor) VALUES (?, ?, ?, ?)')
                ->execute([$variantId, 'rep-product', 'REP-' . $i, 100]);
            $pdo->prepare('INSERT INTO inventory_items (id, variant_id, on_hand, reserved) VALUES (?, ?, ?, ?)')
                ->execute(['rep-stock-' . $i, $variantId, 10, 0]);
            $orderLines[] = [$variantId, 'REP-' . $i, 'Rep ' . $i];
        }
        $this->order('rep-order', 'paid', $orderLines);

        Database::enableQueryCapture();
        Database::resetCapturedQueries();
        self::assertSame(5, (new SmartAggregator())->aggregateReplenishment());

        self::assertSame(1, $this->capturedSql('FROM product_variants WHERE id IN'));
        self::assertSame(0, $this->capturedSql('FROM product_variants WHERE id = ?'));
        $rows = $pdo->query('SELECT variant_id, product_id FROM replenishment_hints')->fetchAll(\PDO::FETCH_ASSOC);
        self::assertCount(5, $rows);
        foreach ($rows as $row) self::assertSame('rep-product', $row['product_id']);
    }
}
