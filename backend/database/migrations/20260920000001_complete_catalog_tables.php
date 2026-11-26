<?php

declare(strict_types=1);

use Siro\Core\DB\Blueprint;
use Siro\Core\Schema;

return new class {
    public function up(): void
    {
        Schema::table('products', function (Blueprint $t) {
            $t->integer('version')->default(1);
            $t->datetime('archived_at')->nullable();
            $t->index(['status', 'archived_at']);
            $t->index('brand_id');
            $t->unique('slug');
        });
        Schema::table('brands', function (Blueprint $t) {
            $t->datetime('archived_at')->nullable();
            $t->index('archived_at');
        });
        Schema::table('categories', function (Blueprint $t) {
            $t->datetime('archived_at')->nullable();
            $t->index('archived_at');
        });
        Schema::create('product_slug_history', function (Blueprint $t) {
            $t->string('id', 26);
            $t->string('product_id', 26);
            $t->string('slug');
            $t->datetime('created_at')->useCurrent();
            $t->primary('id');
            $t->unique('slug');
            $t->index('product_id');
        });
    }

    public function down(): void
    {
        Schema::drop('product_slug_history');
        Schema::dropColumn('categories', 'archived_at');
        Schema::dropColumn('brands', 'archived_at');
        Schema::dropColumn('products', 'archived_at');
        Schema::dropColumn('products', 'version');
    }
};
