<?php

declare(strict_types=1);

use Siro\Core\Database;

/**
 * The products table shipped without a primary key, so an idempotent seeder
 * that re-maps slugs can silently duplicate product ids under the
 * scope+slug unique key. Every other catalog table already has one.
 */
return new class {
    public function up(): void
    {
        if ($this->driver() !== 'mysql') return;
        $pdo = Database::connection();
        $hasPk = (bool) $pdo->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'products' AND INDEX_NAME = 'PRIMARY'")->fetchColumn();
        if (!$hasPk) $pdo->exec('ALTER TABLE products ADD PRIMARY KEY (id)');
    }

    public function down(): void
    {
        if ($this->driver() !== 'mysql') return;
        $pdo = Database::connection();
        $hasPk = (bool) $pdo->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'products' AND INDEX_NAME = 'PRIMARY'")->fetchColumn();
        if ($hasPk) $pdo->exec('ALTER TABLE products DROP PRIMARY KEY');
    }

    private function driver(): string
    {
        try {
            return strtolower((string) Database::connection()->getAttribute(PDO::ATTR_DRIVER_NAME));
        } catch (\Throwable) {
            return '';
        }
    }
};