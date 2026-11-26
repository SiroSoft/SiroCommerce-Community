<?php

declare(strict_types=1);

use Siro\Core\DB\Blueprint;
use Siro\Core\Schema;

return new class {
    public function up(): void
    {
        Schema::create('subscription_plans', function (Blueprint $t): void {
            $t->string('id', 26);
            $t->string('name', 128);
            $t->integer('amount_minor');
            $t->string('currency', 3);
            $t->string('interval', 16);
            $t->integer('interval_count')->default(1);
            $t->string('state', 24)->default('active');
            $t->datetime('created_at')->nullable();
            $t->datetime('updated_at')->nullable();
            $t->primary('id');
            $t->index('state');
        });
        Schema::create('subscriptions', function (Blueprint $t): void {
            $t->string('id', 26);
            $t->string('customer_id', 26);
            $t->string('plan_id', 26);
            $t->string('provider', 64);
            $t->string('currency', 3);
            $t->integer('amount_minor');
            $t->string('interval', 16);
            $t->integer('interval_count')->default(1);
            $t->string('state', 24)->default('active');
            $t->datetime('started_at')->nullable();
            $t->datetime('paused_at')->nullable();
            $t->datetime('cancelled_at')->nullable();
            $t->datetime('next_billing_at');
            $t->datetime('created_at')->nullable();
            $t->datetime('updated_at')->nullable();
            $t->primary('id');
            $t->index(['customer_id', 'state']);
            $t->index(['state', 'next_billing_at']);
        });
        Schema::create('subscription_renewal_attempts', function (Blueprint $t): void {
            $t->string('id', 26);
            $t->string('subscription_id', 26);
            $t->string('idempotency_key', 128);
            $t->string('state', 24)->default('pending');
            $t->integer('amount_minor');
            $t->string('currency', 3);
            $t->string('provider', 64);
            $t->string('payment_operation_id', 26)->nullable();
            $t->text('evidence_json')->nullable();
            $t->text('last_error')->nullable();
            $t->integer('attempts')->default(0);
            $t->datetime('next_attempt_at')->nullable();
            $t->datetime('created_at')->nullable();
            $t->datetime('completed_at')->nullable();
            $t->primary('id');
            $t->unique('idempotency_key');
            $t->index(['subscription_id', 'state']);
        });
    }

    public function down(): void
    {
        Schema::drop('subscription_renewal_attempts');
        Schema::drop('subscriptions');
        Schema::drop('subscription_plans');
    }
};
