<?php
declare(strict_types=1);
use Siro\Core\DB\Blueprint;
use Siro\Core\Schema;
return new class {
    public function up(): void
    {
        Schema::create('erp_webhook_idempotency', function (Blueprint $t) {
            $t->string('idempotency_key', 128);
            $t->text('response_json')->nullable();
            $t->datetime('created_at')->nullable();
            $t->primary('idempotency_key');
            $t->index('created_at');
        });
        // optional snapshot for loyalty (projection)
        Schema::create('customer_loyalty_snapshot', function (Blueprint $t) {
            $t->string('id', 26);
            $t->string('customer_id', 26);
            $t->string('order_id', 26);
            $t->string('tier', 32);
            $t->integer('points_earned');
            $t->datetime('applied_at')->nullable();
            $t->primary('id');
            $t->index('customer_id');
            $t->index('order_id');
        });
    }
    public function down(): void
    {
        Schema::drop('customer_loyalty_snapshot');
        Schema::drop('erp_webhook_idempotency');
    }
};
