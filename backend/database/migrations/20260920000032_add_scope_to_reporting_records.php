<?php

declare(strict_types=1);

use Siro\Core\DB\Blueprint;
use Siro\Core\Schema;

return new class {
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $t): void { $t->string('store_id', 26)->nullable(); $t->string('channel_id', 26)->nullable(); $t->index(['store_id', 'channel_id'], 'idx_orders_scope'); });
        Schema::table('analytics_events', function (Blueprint $t): void { $t->string('store_id', 26)->nullable(); $t->string('channel_id', 26)->nullable(); $t->index(['store_id', 'channel_id', 'created_at'], 'idx_analytics_scope'); });
        Schema::table('bi_exports', function (Blueprint $t): void { $t->string('store_id', 26)->nullable(); $t->string('channel_id', 26)->nullable(); });
    }

    public function down(): void
    {
        Schema::table('bi_exports', function (Blueprint $t): void { $t->dropColumn('channel_id'); $t->dropColumn('store_id'); });
        Schema::table('analytics_events', function (Blueprint $t): void { $t->dropIndex('idx_analytics_scope'); $t->dropColumn('channel_id'); $t->dropColumn('store_id'); });
        Schema::table('orders', function (Blueprint $t): void { $t->dropIndex('idx_orders_scope'); $t->dropColumn('channel_id'); $t->dropColumn('store_id'); });
    }
};
