<?php

declare(strict_types=1);

use Siro\Core\DB\Blueprint;
use Siro\Core\Schema;

return new class {
    public function up(): void
    {
        Schema::table('product_option_values', function (Blueprint $t): void {
            $t->integer('surcharge_minor')->default(0);
            $t->string('currency', 3)->nullable();
        });
        Schema::create('product_attributes', function (Blueprint $t): void {
            $t->string('id', 26);
            $t->string('product_id', 26);
            $t->string('code', 80);
            $t->string('value', 4000);
            $t->string('value_type', 16)->default('string');
            $t->primary('id');
            $t->unique(['product_id', 'code']);
            $t->index('code');
        });
        Schema::create('variant_dependencies', function (Blueprint $t): void {
            $t->string('id', 26);
            $t->string('product_id', 26);
            $t->string('option_value_id', 26);
            $t->string('depends_on_option_value_id', 26);
            $t->string('operator', 16)->default('requires');
            $t->primary('id');
            $t->unique(['product_id', 'option_value_id', 'depends_on_option_value_id'], 'uq_variant_dep_values');
            $t->index('product_id');
        });
        Schema::create('variant_quantity_prices', function (Blueprint $t): void {
            $t->string('id', 26);
            $t->string('variant_id', 26);
            $t->integer('minimum_qty');
            $t->integer('unit_price_minor');
            $t->string('currency', 3);
            $t->primary('id');
            $t->unique(['variant_id', 'minimum_qty', 'currency']);
            $t->index('variant_id');
        });
    }

    public function down(): void
    {
        Schema::drop('variant_quantity_prices');
        Schema::drop('variant_dependencies');
        Schema::drop('product_attributes');
        Schema::table('product_option_values', function (Blueprint $t): void {
            $t->dropColumn('currency');
            $t->dropColumn('surcharge_minor');
        });
    }
};
