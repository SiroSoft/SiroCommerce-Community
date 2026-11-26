<?php

declare(strict_types=1);

use Siro\Core\DB\Blueprint;
use Siro\Core\Schema;

return new class {
    public function up(): void
    {
        Schema::table('products', function (Blueprint $t): void {
            $t->string('approval_status', 24)->default('pending');
            $t->index(['store_id', 'channel_id', 'approval_status'], 'idx_products_approval');
        });
        Schema::create('product_versions', function (Blueprint $t): void {
            $t->string('id', 26);
            $t->string('product_id', 26);
            $t->integer('version');
            $t->string('state', 24);
            $t->string('approval_status', 24);
            $t->text('snapshot_json');
            $t->string('created_by', 26)->nullable();
            $t->datetime('created_at');
            $t->primary('id');
            $t->unique(['product_id', 'version'], 'uq_product_versions');
            $t->index(['product_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::drop('product_versions');
        Schema::table('products', function (Blueprint $t): void {
            $t->dropIndex('idx_products_approval');
            $t->dropColumn('approval_status');
        });
    }
};
