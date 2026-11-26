<?php

declare(strict_types=1);

use Siro\Core\Schema;
use Siro\Core\DB\Blueprint;

return new class {
    public function up(): void
    {
        Schema::table('products', fn (Blueprint $t) => $t->index('name', 'idx_products_name'));
        Schema::table('customers', fn (Blueprint $t) => $t->index('name', 'idx_customers_name'));
        Schema::table('orders', fn (Blueprint $t) => $t->index(['created_at', 'state'], 'idx_orders_created_state'));
        Schema::table('refunds', function (Blueprint $t) {
            $t->datetime('created_at')->nullable();
            $t->index('created_at', 'idx_refunds_created_at');
        });
    }

    public function down(): void
    {
        Schema::table('refunds', function (Blueprint $t) {
            $t->dropIndex('idx_refunds_created_at');
            $t->dropColumn('created_at');
        });
        Schema::table('orders', fn (Blueprint $t) => $t->dropIndex('idx_orders_created_state'));
        Schema::table('customers', fn (Blueprint $t) => $t->dropIndex('idx_customers_name'));
        Schema::table('products', fn (Blueprint $t) => $t->dropIndex('idx_products_name'));
    }
};
