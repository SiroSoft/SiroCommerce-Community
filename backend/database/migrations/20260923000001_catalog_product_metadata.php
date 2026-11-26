<?php

declare(strict_types=1);

use Siro\Core\DB\Blueprint;
use Siro\Core\Schema;

return new class {
    public function up(): void
    {
        Schema::table('products', function (Blueprint $t): void {
            $t->string('category_id', 26)->nullable();
            $t->string('description', 4000)->default('');
            $t->string('tags_json', 4000)->default('[]');
            $t->integer('allow_backorder')->default(0);
            $t->string('meta_title', 255)->nullable();
            $t->string('meta_description', 512)->nullable();
            $t->index('category_id');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $t): void {
            $t->dropIndex('idx_products_category_id');
            $t->dropColumn('meta_description');
            $t->dropColumn('meta_title');
            $t->dropColumn('tags_json');
            $t->dropColumn('allow_backorder');
            $t->dropColumn('description');
            $t->dropColumn('category_id');
        });
    }
};
