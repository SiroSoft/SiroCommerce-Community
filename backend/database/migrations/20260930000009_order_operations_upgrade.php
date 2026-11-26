<?php

declare(strict_types=1);

use Siro\Core\DB\Blueprint;
use Siro\Core\Schema;

return new class {
    public function up(): void
    {
        Schema::table('fulfillment_orders', function (Blueprint $t): void {
            $t->integer('backorder_qty')->default(0);
            $t->string('channel_id', 64)->nullable();
        });
        Schema::table('fulfillment_lines', function (Blueprint $t): void {
            $t->string('reservation_id', 26)->nullable();
        });
        Schema::create('order_timeline_events', function (Blueprint $t): void {
            $t->string('id', 26);
            $t->string('order_id', 32);
            $t->string('event_type', 64);
            $t->string('state', 32)->nullable();
            $t->string('actor', 128)->default('system');
            $t->text('payload_json')->nullable();
            $t->string('store_id', 64)->nullable();
            $t->string('channel_id', 64)->nullable();
            $t->datetime('created_at')->nullable();
            $t->primary('id');
            $t->index(['order_id', 'created_at']);
            $t->index(['store_id', 'channel_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::drop('order_timeline_events');
        Schema::table('fulfillment_orders', function (Blueprint $t): void { $t->dropColumn('channel_id'); $t->dropColumn('backorder_qty'); });
        Schema::table('fulfillment_lines', function (Blueprint $t): void { $t->dropColumn('reservation_id'); });
    }
};
