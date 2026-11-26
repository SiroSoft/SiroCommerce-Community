<?php

declare(strict_types=1);

namespace Tests\Unit;

use PDO;
use Siro\Core\Database;
use Tests\TestCase;

final class AnalyticsEventIndexMigrationTest extends TestCase
{
    private const INDEX = 'idx_analytics_events_event_created';

    public function testCompositeEventCreatedIndexAppliesAndRollsBack(): void
    {
        $migration = require dirname(__DIR__, 2) . '/database/migrations/20261016000001_add_analytics_event_created_index.php';
        $pdo = Database::connection();

        // Baseline migrations already applied up(); start from a clean down state.
        $migration->down();
        self::assertArrayNotHasKey(self::INDEX, $this->indexes($pdo));

        $migration->up();
        $indexes = $this->indexes($pdo);
        self::assertArrayHasKey(self::INDEX, $indexes);
        self::assertSame(['event', 'created_at'], $indexes[self::INDEX]);

        $migration->down();
        self::assertArrayNotHasKey(self::INDEX, $this->indexes($pdo));

        // Restore the schema expected by any following test in this class.
        $migration->up();
        self::assertArrayHasKey(self::INDEX, $this->indexes($pdo));
    }

    /** @return array<string, array<int, string>> */
    private function indexes(PDO $pdo): array
    {
        $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $indexes = [];

        if ($driver === 'sqlite') {
            $list = $pdo->query("PRAGMA index_list('analytics_events')")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($list as $row) {
                $name = (string) ($row['name'] ?? '');
                if ($name === '') {
                    continue;
                }
                $columns = [];
                $info = $pdo->query("PRAGMA index_info(" . $pdo->quote($name) . ")")->fetchAll(PDO::FETCH_ASSOC);
                usort($info, static fn(array $a, array $b): int => (int) ($a['seqno'] ?? 0) <=> (int) ($b['seqno'] ?? 0));
                foreach ($info as $column) {
                    $columns[] = (string) ($column['name'] ?? '');
                }
                $indexes[$name] = $columns;
            }
            return $indexes;
        }

        $rows = $pdo->query('SHOW INDEX FROM `analytics_events`')->fetchAll(PDO::FETCH_ASSOC);
        usort($rows, static fn(array $a, array $b): int => (int) ($a['Seq_in_index'] ?? 0) <=> (int) ($b['Seq_in_index'] ?? 0));
        foreach ($rows as $row) {
            $name = (string) ($row['Key_name'] ?? '');
            if ($name === '') {
                continue;
            }
            $indexes[$name][] = (string) ($row['Column_name'] ?? '');
        }

        return $indexes;
    }
}
