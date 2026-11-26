<?php

declare(strict_types=1);

use Siro\Core\DB\Blueprint;
use Siro\Core\Schema;

return new class {
    public function up(): void
    {
        Schema::create('attributes', function (Blueprint $t) {
            $t->string('id', 26);
            $t->string('code', 64);
            $t->string('name', 128);
            $t->string('type', 32)->default('text');
            $t->integer('filterable')->default(0);
            $t->primary('id');
            $t->unique('code');
        });
        Schema::create('attribute_values', function (Blueprint $t) {
            $t->string('id', 26);
            $t->string('attribute_id', 26);
            $t->string('value', 255);
            $t->primary('id');
            $t->unique(['attribute_id', 'value']);
        });
        Schema::create('product_attribute_values', function (Blueprint $t) {
            $t->string('product_id', 26);
            $t->string('attribute_value_id', 26);
            $t->primary(['product_id', 'attribute_value_id']);
        });
        Schema::create('category_products', function (Blueprint $t) {
            $t->string('category_id', 26);
            $t->string('product_id', 26);
            $t->integer('sort')->default(0);
            $t->primary(['category_id', 'product_id']);
        });
        Schema::create('customer_events', function (Blueprint $t) {
            $t->string('id', 26);
            $t->string('customer_id', 26);
            $t->string('type', 64);
            $t->string('consent_version', 32)->nullable();
            $t->datetime('occurred_at')->nullable();
            $t->primary('id');
            $t->index(['customer_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::drop('customer_events');
        Schema::drop('category_products');
        Schema::drop('product_attribute_values');
        Schema::drop('attribute_values');
        Schema::drop('attributes');
    }
};
