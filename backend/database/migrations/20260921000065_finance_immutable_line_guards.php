<?php
declare(strict_types=1);

use Siro\Core\Database;

return new class {
    public function up(): void
    {
        $pdo = Database::connection();
        $driverValue = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if (!is_string($driverValue)) {
            throw new RuntimeException('Database driver name is unavailable');
        }
        $driver = $driverValue;

        if ($driver === 'sqlite') {
            foreach (['invoice_lines', 'credit_memo_lines'] as $table) {
                $name = str_replace('_', '', $table);
                $pdo->exec("CREATE TRIGGER {$name}_immutable_update BEFORE UPDATE ON {$table} BEGIN SELECT RAISE(ABORT, 'Finance evidence lines are append-only'); END");
                $pdo->exec("CREATE TRIGGER {$name}_immutable_delete BEFORE DELETE ON {$table} BEGIN SELECT RAISE(ABORT, 'Finance evidence lines are append-only'); END");
            }
            return;
        }

        if ($driver === 'mysql' || $driver === 'mariadb') {
            foreach (['invoice_lines', 'credit_memo_lines'] as $table) {
                $name = str_replace('_', '', $table);
                $pdo->exec("CREATE TRIGGER {$name}_immutable_update BEFORE UPDATE ON {$table} FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Finance evidence lines are append-only'");
                $pdo->exec("CREATE TRIGGER {$name}_immutable_delete BEFORE DELETE ON {$table} FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Finance evidence lines are append-only'");
            }
            return;
        }

        throw new RuntimeException("Unsupported database driver for finance evidence guards: {$driver}");
    }

    public function down(): void
    {
        $pdo = Database::connection();
        $driverValue = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if (!is_string($driverValue)) {
            throw new RuntimeException('Database driver name is unavailable');
        }
        $driver = $driverValue;
        foreach (['invoicelines', 'creditmemolines'] as $name) {
            if ($driver === 'sqlite') {
                $pdo->exec("DROP TRIGGER IF EXISTS {$name}_immutable_update");
                $pdo->exec("DROP TRIGGER IF EXISTS {$name}_immutable_delete");
            } elseif ($driver === 'mysql' || $driver === 'mariadb') {
                $pdo->exec("DROP TRIGGER IF EXISTS {$name}_immutable_update");
                $pdo->exec("DROP TRIGGER IF EXISTS {$name}_immutable_delete");
            }
        }
    }
};
