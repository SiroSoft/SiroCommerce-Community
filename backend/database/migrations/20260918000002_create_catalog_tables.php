<?php

declare(strict_types=1);

use Siro\Core\DB\Blueprint;
use Siro\Core\Schema;

return new class {
    public function up(): void
    {
        Schema::create('brands', function (Blueprint $t) {
            $t->string('id', 26);
            $t->string('name');
            $t->string('slug');
            $t->timestamps();
            $t->primary('id');
            $t->unique('slug');
        });
        Schema::create('categories', function (Blueprint $t) {
            $t->string('id', 26);
            $t->string('parent_id', 26)->nullable();
            $t->string('name');
            $t->string('slug');
            $t->integer('sort')->default(0);
            $t->primary('id');
            $t->unique('slug');
            $t->index('parent_id');
        });
        Schema::create('product_variants', function (Blueprint $t) {
            $t->string('id', 26);
            $t->string('product_id', 26);
            $t->string('sku', 64);
            $t->integer('price_minor');
            $t->integer('compare_price_minor')->nullable();
            $t->string('tax_class', 32)->default('standard');
            $t->integer('weight_g')->default(0);
            $t->primary('id');
            $t->unique('sku');
            $t->index('product_id');
        });
        Schema::create('product_images', function (Blueprint $t) {
            $t->string('id', 26);
            $t->string('product_id', 26);
            $t->string('url', 512);
            $t->string('alt')->default('');
            $t->integer('sort')->default(0);
            $t->primary('id');
            $t->index('product_id');
        });
    }

    public function down(): void
    {
        Schema::drop('product_images');
        Schema::drop('product_variants');
        Schema::drop('categories');
        Schema::drop('brands');
    }
};
