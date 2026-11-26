<?php

declare(strict_types=1);

use Siro\Core\DB\Blueprint;
use Siro\Core\Schema;

return new class {
    public function up(): void
    {
        Schema::create('pricing_customer_groups', function (Blueprint $t) {
            $t->string('id', 26);
            $t->string('code', 64);
            $t->string('name', 128);
            $t->string('state', 16)->default('active');
            $t->datetime('created_at')->nullable();
            $t->primary('id');
            $t->unique('code');
        });
        Schema::create('pricing_price_lists', function (Blueprint $t) {
            $t->string('id', 26);
            $t->string('code', 64);
            $t->string('name', 128);
            $t->string('currency', 3);
            $t->string('customer_group_id', 26)->nullable();
            $t->integer('priority')->default(0);
            $t->string('state', 16)->default('active');
            $t->primary('id');
            $t->unique('code');
            $t->index(['currency', 'customer_group_id', 'state']);
        });
        Schema::create('pricing_price_list_prices', function (Blueprint $t) {
            $t->string('id', 26);
            $t->string('price_list_id', 26);
            $t->string('variant_id', 26);
            $t->integer('unit_price_minor');
            $t->primary('id');
            $t->unique(['price_list_id', 'variant_id']);
            $t->index('variant_id');
        });
        Schema::create('pricing_customer_group_prices', function (Blueprint $t) {
            $t->string('id', 26);
            $t->string('customer_group_id', 26);
            $t->string('variant_id', 26);
            $t->string('currency', 3);
            $t->integer('unit_price_minor');
            $t->primary('id');
            $t->unique(['customer_group_id', 'variant_id', 'currency'], 'uq_customer_group_price');
            $t->index('variant_id');
        });
        Schema::create('pricing_currency_rates', function (Blueprint $t) {
            $t->string('id', 26);
            $t->string('base_currency', 3);
            $t->string('quote_currency', 3);
            $t->integer('rate_numerator');
            $t->integer('rate_denominator');
            $t->string('state', 16)->default('active');
            $t->datetime('effective_at')->nullable();
            $t->primary('id');
            $t->unique(['base_currency', 'quote_currency']);
        });
    }

    public function down(): void
    {
        Schema::drop('pricing_currency_rates');
        Schema::drop('pricing_customer_group_prices');
        Schema::drop('pricing_price_list_prices');
        Schema::drop('pricing_price_lists');
        Schema::drop('pricing_customer_groups');
    }
};
