<?php
declare(strict_types=1);

use Siro\Core\Database;

return new class {
    public function up(): void
    {
        $pdo = Database::connection();
        if (strtolower((string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME)) !== 'mysql') return;
        $column = $pdo->query("SHOW COLUMNS FROM storefront_config_revisions LIKE 'id'")->fetch(PDO::FETCH_ASSOC);
        $type = is_array($column) ? strtolower((string) ($column['Type'] ?? '')) : '';
        if ($type !== '' && !str_contains($type, 'varchar')) {
            $pdo->exec('ALTER TABLE storefront_config_revisions MODIFY id VARCHAR(64) NOT NULL');
        }
    }

    public function down(): void
    {
        // Existing opaque revision IDs cannot safely be converted back to integers.
    }
};
