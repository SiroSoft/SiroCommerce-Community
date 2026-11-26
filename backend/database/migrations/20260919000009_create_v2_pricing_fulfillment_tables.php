<?php

declare(strict_types=1);

use Siro\Core\DB\Blueprint;
use Siro\Core\Schema;

return new class {
    public function up(): void
    {
        Schema::create('pricing_snapshots', function (Blueprint $t) {
            $t->string('id', 26);
            $t->string('owner_type', 32);
            $t->string('owner_id', 26);
            $t->string('currency', 3);
            $t->string('engine_version', 32);
            $t->text('lines_json');
            $t->text('rules_json')->nullable();
            $t->text('taxes_json')->nullable();
            $t->integer('subtotal_minor');
            $t->integer('discount_minor');
            $t->integer('shipping_minor');
            $t->integer('tax_minor');
            $t->integer('grand_total_minor');
            $t->datetime('expires_at')->nullable();
            $t->datetime('created_at')->nullable();
            $t->primary('id');
            $t->unique(['owner_type', 'owner_id']);
        });
        Schema::create('fulfillment_orders', function (Blueprint $t) {
            $t->string('id', 26);
            $t->string('order_id', 26);
            $t->string('state', 32)->default('pending');
            $t->string('idempotency_key', 128);
            $t->datetime('created_at')->nullable();
            $t->primary('id');
            $t->unique('idempotency_key');
            $t->index(['order_id', 'state']);
        });
        Schema::create('fulfillment_lines', function (Blueprint $t) {
            $t->string('id', 26);
            $t->string('fulfillment_id', 26);
            $t->string('order_line_id', 26);
            $t->integer('qty');
            $t->primary('id');
            $t->index('fulfillment_id');
        });
        Schema::create('packages', function (Blueprint $t) {
            $t->string('id', 26);
            $t->string('fulfillment_id', 26);
            $t->string('carrier', 64);
            $t->string('provider_ref', 255)->nullable();
            $t->string('state', 32)->default('pending');
            $t->string('idempotency_key', 128);
            $t->string('tracking', 128)->nullable();
            $t->primary('id');
            $t->unique('idempotency_key');
            $t->index('fulfillment_id');
        });
        Schema::create('return_requests', function (Blueprint $t) {
            $t->string('id', 26);
            $t->string('order_id', 26);
            $t->string('fulfillment_id', 26);
            $t->string('state', 32)->default('requested');
            $t->string('idempotency_key', 128);
            $t->string('reason')->default('');
            $t->datetime('created_at')->nullable();
            $t->datetime('completed_at')->nullable();
            $t->primary('id');
            $t->unique('idempotency_key');
            $t->index(['order_id', 'state']);
        });
        Schema::create('return_lines', function (Blueprint $t) {
            $t->string('id', 26);
            $t->string('return_id', 26);
            $t->string('order_line_id', 26);
            $t->integer('qty');
            $t->string('reason')->default('');
            $t->primary('id');
            $t->index('return_id');
        });
    }

    public function down(): void
    {
        Schema::drop('return_lines');
        Schema::drop('return_requests');
        Schema::drop('packages');
        Schema::drop('fulfillment_lines');
        Schema::drop('fulfillment_orders');
        Schema::drop('pricing_snapshots');
    }
};
