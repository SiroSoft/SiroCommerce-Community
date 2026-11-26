<?php

declare(strict_types=1);

use Siro\Core\DB\Blueprint;
use Siro\Core\Schema;

return new class {
    public function up(): void
    {
        Schema::create('booking_slots', function (Blueprint $t) {
            $t->string('id', 26);
            $t->string('variant_id', 26);
            $t->datetime('starts_at');
            $t->datetime('ends_at');
            $t->integer('capacity');
            $t->integer('price_minor')->nullable();
            $t->string('currency', 3)->nullable();
            $t->primary('id');
            $t->index(['variant_id', 'starts_at']);
        });
        Schema::create('booking_reservations', function (Blueprint $t) {
            $t->string('id', 26);
            $t->string('variant_id', 26);
            $t->datetime('slot_start');
            $t->string('order_id', 26);
            $t->integer('qty');
            $t->string('state', 16)->default('reserved');
            $t->datetime('created_at')->nullable();
            $t->primary('id');
            $t->index('variant_id');
            $t->index('order_id');
            $t->index('state');
        });
    }

    public function down(): void
    {
        Schema::drop('booking_reservations');
        Schema::drop('booking_slots');
    }
};
