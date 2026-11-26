<?php

declare(strict_types=1);

use Siro\Core\DB\Blueprint;
use Siro\Core\Schema;

return new class {
    public function up(): void
    {
        Schema::create('inventory_items', function (Blueprint $t) {
            $t->string('id', 26);
            $t->string('variant_id', 26);
            $t->integer('on_hand')->default(0);
            $t->integer('reserved')->default(0);
            $t->integer('low_threshold')->default(0);
            $t->primary('id');
            $t->unique('variant_id');
        });
        Schema::create('stock_ledger', function (Blueprint $t) {
            $t->string('id', 26);
            $t->string('variant_id', 26);
            $t->integer('delta');
            $t->string('reason', 64);
            $t->string('ref_type', 32)->nullable();
            $t->string('ref_id', 26)->nullable();
            $t->string('actor', 64);
            $t->datetime('created_at')->nullable();
            $t->primary('id');
            $t->index('variant_id');
        });
        Schema::create('reservations', function (Blueprint $t) {
            $t->string('id', 26);
            $t->string('variant_id', 26);
            $t->string('cart_id', 26)->nullable();
            $t->string('order_id', 26)->nullable();
            $t->integer('qty');
            $t->string('state', 20)->default('held');
            $t->datetime('expires_at')->nullable();
            $t->primary('id');
            $t->index('variant_id');
        });
    }

    public function down(): void
    {
        Schema::drop('reservations');
        Schema::drop('stock_ledger');
        Schema::drop('inventory_items');
    }
};
