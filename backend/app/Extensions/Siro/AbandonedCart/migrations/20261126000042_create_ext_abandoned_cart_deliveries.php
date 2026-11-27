<?php

declare(strict_types=1);

use Siro\Core\DB\Blueprint;
use Siro\Core\Schema;

return new class {
    public function up(): void
    {
        Schema::create('ext_abandoned_cart_deliveries', function (Blueprint $t) {
            $t->string('id', 26);
            $t->string('store_id', 64);
            $t->string('channel_id', 64);
            $t->string('cart_id', 64);
            $t->string('signal', 64);
            $t->integer('step')->default(1);
            $t->datetime('sent_at');
            $t->string('idempotency_key', 128);
            $t->datetime('created_at')->nullable();
            $t->primary('id');
            $t->unique(['store_id', 'channel_id', 'idempotency_key']);
            $t->index(['store_id', 'channel_id', 'cart_id']);
        });
    }

    public function down(): void
    {
        Schema::drop('ext_abandoned_cart_deliveries');
    }
};
