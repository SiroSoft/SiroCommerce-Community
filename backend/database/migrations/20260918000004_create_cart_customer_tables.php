<?php

declare(strict_types=1);

use Siro\Core\DB\Blueprint;
use Siro\Core\Schema;

return new class {
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $t) {
            $t->string('id', 26);
            $t->string('email');
            $t->string('name')->default('');
            $t->datetime('created_at')->nullable();
            $t->primary('id');
            $t->unique('email');
        });
        Schema::create('addresses', function (Blueprint $t) {
            $t->string('id', 26);
            $t->string('customer_id', 26)->nullable();
            $t->string('label', 64)->default('home');
            $t->string('line1');
            $t->string('city', 128);
            $t->string('phone', 32);
            $t->primary('id');
            $t->index('customer_id');
        });
        Schema::create('carts', function (Blueprint $t) {
            $t->string('id', 26);
            $t->string('customer_id', 26)->nullable();
            $t->string('currency', 3)->default('VND');
            $t->string('state', 20)->default('open');
            $t->timestamps();
            $t->primary('id');
        });
        Schema::create('cart_lines', function (Blueprint $t) {
            $t->string('id', 26);
            $t->string('cart_id', 26);
            $t->string('variant_id', 26);
            $t->integer('qty');
            $t->integer('unit_price_minor');
            $t->primary('id');
            $t->index('cart_id');
        });
    }

    public function down(): void
    {
        Schema::drop('cart_lines');
        Schema::drop('carts');
        Schema::drop('addresses');
        Schema::drop('customers');
    }
};
