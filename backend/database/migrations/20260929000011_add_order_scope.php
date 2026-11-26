<?php

declare(strict_types=1);

use Siro\Core\DB\Blueprint;
use Siro\Core\Schema;

return new class {
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $t): void {
            $t->string('tenant_id', 64)->nullable();
            $t->string('seller_id', 64)->nullable();
            $t->string('site_id', 64)->nullable();
            $t->index(['tenant_id', 'seller_id', 'site_id', 'store_id', 'channel_id'], 'idx_orders_commerce_scope');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $t): void {
            $t->dropIndex('idx_orders_commerce_scope');
            $t->dropColumn('site_id');
            $t->dropColumn('seller_id');
            $t->dropColumn('tenant_id');
        });
    }
};
