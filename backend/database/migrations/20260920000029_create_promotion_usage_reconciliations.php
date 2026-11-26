<?php
declare(strict_types=1);

use Siro\Core\DB\Blueprint;
use Siro\Core\Schema;

return new class {
    public function up(): void
    {
        Schema::create('promotion_usage_reconciliations', function (Blueprint $t): void {
            $t->string('id', 26);
            $t->string('coupon_id', 64)->nullable();
            $t->string('order_id', 64);
            $t->string('idempotency_key', 128);
            $t->integer('released_discount_minor')->default(0);
            $t->string('currency', 3)->default('VND');
            $t->string('reason', 120)->nullable();
            $t->text('evidence_json')->nullable();
            $t->datetime('created_at')->nullable();
            $t->unique('idempotency_key');
            $t->index(['order_id', 'coupon_id']);
            $t->primary('id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('promotion_usage_reconciliations');
    }
};
