<?php

declare(strict_types=1);

use Siro\Core\DB\Blueprint;
use Siro\Core\Schema;

/**
 * Funnel and admin analytics queries filter by event type and then order/
 * range on created_at. The single-column `event` index (created with the
 * base table) forces a sort; this composite index serves both predicates.
 *
 * Name is explicit so down() can drop exactly this index on SQLite/MySQL.
 */
return new class {
    private const INDEX = 'idx_analytics_events_event_created';

    public function up(): void
    {
        Schema::table('analytics_events', function (Blueprint $t): void {
            $t->index(['event', 'created_at'], self::INDEX);
        });
    }

    public function down(): void
    {
        Schema::table('analytics_events', function (Blueprint $t): void {
            $t->dropIndex(self::INDEX);
        });
    }
};
