<?php

declare(strict_types=1);

use Siro\Core\DB\Blueprint;
use Siro\Core\Schema;

return new class {
    public function up(): void
    {
        // Match the catalog browse scope and its stable descending page order.
        Schema::table('products', function (Blueprint $t): void {
            $t->index(['status', 'archived_at', 'store_id', 'channel_id', 'locale', 'currency', 'created_at', 'id'], 'idx_products_browse_scope');
        });
        // Customer history and admin scoped order pages use these leading predicates.
        Schema::table('orders', function (Blueprint $t): void {
            $t->index(['customer_id', 'created_at', 'id'], 'idx_orders_customer_created');
            $t->index(['store_id', 'channel_id', 'created_at', 'id'], 'idx_orders_scope_created');
            $t->index(['state', 'created_at', 'id'], 'idx_orders_state_created');
        });
        // Recovery scans must not sort or scan completed/dead rows.
        Schema::table('commerce_jobs', function (Blueprint $t): void {
            $t->datetime('completed_at')->nullable();
            $t->index(['state', 'available_at', 'attempts'], 'idx_commerce_jobs_ready');
        });
    }

    public function down(): void
    {
        Schema::table('commerce_jobs', function (Blueprint $t): void {
            $t->dropIndex('idx_commerce_jobs_ready');
            $t->dropColumn('completed_at');
        });
        Schema::table('orders', function (Blueprint $t): void {
            $t->dropIndex('idx_orders_state_created');
            $t->dropIndex('idx_orders_scope_created');
            $t->dropIndex('idx_orders_customer_created');
        });
        Schema::table('products', function (Blueprint $t): void {
            $t->dropIndex('idx_products_browse_scope');
        });
    }
};
