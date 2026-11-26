<?php

declare(strict_types=1);

use Siro\Core\DB\Blueprint;
use Siro\Core\Schema;

return new class {
    public function up(): void
    {
        Schema::create('company_credit_terms', function (Blueprint $t) {
            $t->string('id', 26); $t->string('company_id', 26); $t->integer('payment_terms_days')->default(0);
            $t->text('evidence_json'); $t->timestamps(); $t->primary('id'); $t->unique('company_id');
        });
        Schema::create('company_order_workflows', function (Blueprint $t) {
            $t->string('order_id', 26); $t->string('company_id', 26); $t->string('state', 20)->default('draft');
            $t->integer('submitted_by')->nullable(); $t->integer('approved_by')->nullable(); $t->integer('rejected_by')->nullable();
            $t->string('rejection_reason')->nullable(); $t->timestamps(); $t->primary('order_id'); $t->index(['company_id', 'state']);
        });
        Schema::create('company_credit_reservations', function (Blueprint $t) {
            $t->string('id', 26); $t->string('company_id', 26); $t->string('order_id', 26); $t->string('currency', 3);
            $t->integer('amount_minor'); $t->string('state', 20)->default('reserved'); $t->string('idempotency_key', 255);
            $t->timestamps(); $t->primary('id'); $t->unique('order_id'); $t->unique('idempotency_key'); $t->index(['company_id', 'state']);
        });
        Schema::create('company_order_transitions', function (Blueprint $t) {
            $t->string('id', 26); $t->string('order_id', 26); $t->string('from_state', 20); $t->string('to_state', 20);
            $t->integer('user_id'); $t->string('idempotency_key', 255); $t->string('reason')->default(''); $t->timestamps();
            $t->primary('id'); $t->unique('idempotency_key'); $t->index('order_id');
        });
    }

    public function down(): void
    {
        Schema::drop('company_order_transitions'); Schema::drop('company_credit_reservations');
        Schema::drop('company_order_workflows'); Schema::drop('company_credit_terms');
    }
};
