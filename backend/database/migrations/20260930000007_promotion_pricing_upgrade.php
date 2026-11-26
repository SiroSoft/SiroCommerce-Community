<?php
declare(strict_types=1);
use Siro\Core\DB\Blueprint;
use Siro\Core\Schema;

return new class {
    public function up(): void
    {
        Schema::create('promotion_customer_segments', function (Blueprint $t) {
            $t->string('id', 26); $t->string('code', 64); $t->string('name', 128); $t->string('state', 16)->default('active');
            $t->datetime('created_at')->nullable(); $t->primary('id'); $t->unique('code');
        });
        Schema::create('promotion_segment_members', function (Blueprint $t) {
            $t->string('segment_id', 26); $t->string('customer_id', 26); $t->datetime('created_at')->nullable();
            $t->primary(['segment_id', 'customer_id']); $t->index('customer_id');
        });
        Schema::create('promotion_campaigns', function (Blueprint $t) {
            $t->string('id', 26); $t->string('code', 64); $t->string('state', 16)->default('scheduled');
            $t->datetime('starts_at')->nullable(); $t->datetime('ends_at')->nullable(); $t->text('rules')->nullable();
            $t->datetime('created_at')->nullable(); $t->primary('id'); $t->unique('code'); $t->index(['state', 'starts_at', 'ends_at']);
        });
        Schema::create('promotion_decision_audits', function (Blueprint $t) {
            $t->string('id', 26); $t->string('coupon_id', 26)->nullable(); $t->string('code_hash', 128);
            $t->string('decision', 16); $t->string('reason', 128)->default(''); $t->string('currency', 3);
            $t->integer('subtotal_minor')->default(0); $t->integer('discount_minor')->default(0);
            $t->string('actor_hash', 128)->nullable(); $t->text('context_json')->nullable(); $t->datetime('created_at')->nullable();
            $t->primary('id'); $t->index(['coupon_id', 'created_at']); $t->index(['code_hash', 'created_at']);
        });
    }
    public function down(): void
    {
        Schema::drop('promotion_decision_audits'); Schema::drop('promotion_campaigns'); Schema::drop('promotion_segment_members'); Schema::drop('promotion_customer_segments');
    }
};
