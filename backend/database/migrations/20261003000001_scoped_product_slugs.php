<?php

declare(strict_types=1);

use Siro\Core\DB\Blueprint;
use Siro\Core\Schema;

return new class {
    public function up(): void
    {
        Schema::table('products', function (Blueprint $t): void {
            $t->dropUnique('uq_products_slug');
            $t->unique(['store_id', 'channel_id', 'locale', 'currency', 'slug'], 'uq_products_scope_slug');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $t): void {
            $t->dropUnique('uq_products_scope_slug');
            $t->unique('slug', 'uq_products_slug');
        });
    }
};
