<?php

declare(strict_types=1);

use Siro\Core\DB\Blueprint;
use Siro\Core\Schema;

return new class {
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $t) {
            $t->string('id', 32);
            $t->string('number', 32);
            $t->string('customer_id', 26)->nullable();
            $t->string('guest_email')->nullable();
            $t->string('currency', 3)->default('VND');
            $t->integer('subtotal_minor');
            $t->integer('discount_minor')->default(0);
            $t->integer('shipping_minor')->default(0);
            $t->integer('tax_minor')->default(0);
            $t->integer('grand_total_minor');
            $t->string('state', 32)->default('pending');
            $t->string('idempotency_key', 64);
            $t->datetime('created_at')->nullable();
            $t->primary('id');
            $t->unique('number');
            $t->unique('idempotency_key');
            $t->index('state');
        });
        Schema::create('order_lines', function (Blueprint $t) {
            $t->string('id', 26);
            $t->string('order_id', 32);
            $t->string('variant_id', 26);
            $t->string('sku', 64);
            $t->string('name');
            $t->integer('qty');
            $t->integer('unit_price_minor');
            $t->primary('id');
            $t->index('order_id');
        });
        Schema::create('payments', function (Blueprint $t) {
            $t->string('id', 26);
            $t->string('order_id', 32);
            $t->string('provider', 32);
            $t->integer('amount_minor');
            $t->string('state', 32)->default('pending');
            $t->string('provider_ref', 128)->nullable();
            $t->string('idempotency_key', 64);
            $t->primary('id');
            $t->unique('idempotency_key');
            $t->index('order_id');
        });
        Schema::create('shipments', function (Blueprint $t) {
            $t->string('id', 26);
            $t->string('order_id', 32);
            $t->string('provider', 32);
            $t->string('tracking', 128)->nullable();
            $t->string('state', 32)->default('pending');
            $t->primary('id');
            $t->index('order_id');
        });
        Schema::create('refunds', function (Blueprint $t) {
            $t->string('id', 26);
            $t->string('order_id', 32);
            $t->string('payment_id', 26);
            $t->integer('amount_minor');
            $t->string('reason')->default('');
            $t->string('state', 32)->default('pending');
            $t->primary('id');
            $t->index('order_id');
        });
        Schema::create('order_transitions', function (Blueprint $t) {
            $t->string('id', 26);
            $t->string('order_id', 32);
            $t->string('from_state', 32);
            $t->string('to_state', 32);
            $t->string('actor', 64);
            $t->string('reason')->default('');
            $t->datetime('created_at')->nullable();
            $t->primary('id');
            $t->index('order_id');
        });
    }

    public function down(): void
    {
        Schema::drop('order_transitions');
        Schema::drop('refunds');
        Schema::drop('shipments');
        Schema::drop('payments');
        Schema::drop('order_lines');
        Schema::drop('orders');
    }
};
