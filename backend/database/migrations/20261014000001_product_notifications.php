<?php

declare(strict_types=1);

use Siro\Core\DB\Blueprint;
use Siro\Core\Schema;

return new class {
    public function up(): void
    {
        Schema::table('stock_alerts', function (Blueprint $t): void {
            $t->string('type', 24)->default('back_in_stock');
            $t->integer('target_price_minor')->nullable();
            $t->integer('price_at_subscribe_minor')->nullable();
            $t->integer('attempts')->default(0);
            $t->string('last_error', 255)->nullable();
            $t->datetime('sent_at')->nullable();
            $t->index(['type', 'state']);
        });
        Schema::table('products', function (Blueprint $t): void {
            $t->integer('is_preorder')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $t): void {
            $t->dropColumn('is_preorder');
        });
        Schema::table('stock_alerts', function (Blueprint $t): void {
            $t->dropColumn('type');
            $t->dropColumn('target_price_minor');
            $t->dropColumn('price_at_subscribe_minor');
            $t->dropColumn('attempts');
            $t->dropColumn('last_error');
            $t->dropColumn('sent_at');
        });
    }
};
