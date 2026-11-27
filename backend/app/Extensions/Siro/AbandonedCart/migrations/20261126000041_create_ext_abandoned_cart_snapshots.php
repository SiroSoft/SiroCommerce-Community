<?php

declare(strict_types=1);

use Siro\Core\DB\Blueprint;
use Siro\Core\Schema;

return new class {
    public function up(): void
    {
        Schema::create('ext_abandoned_cart_snapshots', function (Blueprint $t) {
            $t->string('id', 26);
            $t->string('store_id', 64);
            $t->string('channel_id', 64);
            $t->string('cart_id', 64);
            $t->string('customer_id', 64)->nullable();
            $t->text('snapshot_json');
            $t->datetime('abandoned_at');
            $t->datetime('created_at')->nullable();
            $t->datetime('updated_at')->nullable();
            $t->primary('id');
            $t->unique(['store_id', 'channel_id', 'cart_id']);
            $t->index(['store_id', 'channel_id', 'abandoned_at']);
            $t->index(['store_id', 'channel_id', 'customer_id']);
        });
    }

    public function down(): void
    {
        Schema::drop('ext_abandoned_cart_snapshots');
    }
};
