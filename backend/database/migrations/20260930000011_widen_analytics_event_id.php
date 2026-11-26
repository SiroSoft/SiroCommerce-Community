<?php

declare(strict_types=1);

use Siro\Core\Database;

return new class {
    public function up(): void
    {
        if (Database::connection()->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
            Database::connection()->exec('ALTER TABLE `analytics_events` MODIFY `event_id` VARCHAR(64) NULL');
        }
    }

    public function down(): void
    {
        if (Database::connection()->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
            Database::connection()->exec('ALTER TABLE `analytics_events` MODIFY `event_id` VARCHAR(26) NULL');
        }
    }
};
