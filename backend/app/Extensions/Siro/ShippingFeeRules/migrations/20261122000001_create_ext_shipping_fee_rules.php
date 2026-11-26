<?php

declare(strict_types=1);

use Siro\Core\DB\Blueprint;
use Siro\Core\Schema;

return new class {
    public function up(): void
    {
        Schema::create('ext_shipping_fee_rules', function (Blueprint $t) {
            $t->string('id', 26);
            $t->string('store_id', 64);
            $t->string('channel_id', 64);
            $t->string('name', 120);
            $t->string('method', 16);
            $t->integer('min_subtotal_minor')->default(0);
            $t->integer('fee_minor')->default(0);
            $t->integer('free_over_minor')->default(0);
            $t->boolean('active')->default(true);
            $t->integer('priority')->default(100);
            $t->string('idempotency_key', 128)->nullable();
            $t->datetime('created_at')->nullable();
            $t->datetime('updated_at')->nullable();
            $t->primary('id');
            $t->unique(['store_id', 'channel_id', 'name', 'method']);
            $t->unique(['store_id', 'channel_id', 'idempotency_key']);
            $t->index(['store_id', 'channel_id', 'method', 'active', 'priority']);
        });
    }

    public function down(): void
    {
        Schema::drop('ext_shipping_fee_rules');
    }
};
