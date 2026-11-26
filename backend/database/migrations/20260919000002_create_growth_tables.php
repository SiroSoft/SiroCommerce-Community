<?php

declare(strict_types=1);

use Siro\Core\DB\Blueprint;
use Siro\Core\Schema;

// Funnel analytics + coupon usage ledger (spec 05§3, 21 events).
return new class {
    public function up(): void
    {
        Schema::create('analytics_events', function (Blueprint $t) {
            $t->string('id', 26);
            $t->string('event', 64);
            $t->string('entity_type', 32)->nullable();
            $t->string('entity_id', 26)->nullable();
            $t->string('session_key', 64)->nullable();
            $t->integer('value_minor')->default(0);
            $t->datetime('created_at')->nullable();
            $t->primary('id');
            $t->index('event');
        });
        Schema::create('coupon_usages', function (Blueprint $t) {
            $t->string('id', 26);
            $t->string('coupon_id', 26);
            $t->string('order_id', 26);
            $t->integer('discount_minor');
            $t->datetime('created_at')->nullable();
            $t->primary('id');
            $t->index('coupon_id');
        });
    }

    public function down(): void
    {
        Schema::drop('coupon_usages');
        Schema::drop('analytics_events');
    }
};
