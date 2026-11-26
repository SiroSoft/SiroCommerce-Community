<?php

declare(strict_types=1);

use Siro\Core\DB\Blueprint;
use Siro\Core\Schema;

return new class {
    public function up(): void
    {
        Schema::create('order_notification_deliveries', function (Blueprint $t) {
            $t->string('id', 26);
            $t->string('delivery_key', 255);
            $t->string('event_name', 128);
            $t->string('aggregate_id', 64);
            $t->string('template', 64);
            $t->string('state', 24)->default('queued');
            $t->integer('attempts')->default(0);
            $t->datetime('locked_at')->nullable();
            $t->text('last_error')->nullable();
            $t->datetime('sent_at')->nullable();
            $t->datetime('created_at')->nullable();
            $t->primary('id');
            $t->unique('delivery_key');
            $t->index(['aggregate_id', 'template']);
        });
    }

    public function down(): void
    {
        Schema::drop('order_notification_deliveries');
    }
};
