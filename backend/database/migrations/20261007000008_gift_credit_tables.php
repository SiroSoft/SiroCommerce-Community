<?php

declare(strict_types=1);

use Siro\Core\DB\Blueprint;
use Siro\Core\Schema;

return new class {
    public function up(): void
    {
        Schema::create('gift_credits', function (Blueprint $t) {
            $t->string('id', 26);
            $t->string('code', 32)->nullable();
            $t->string('owner_customer_id', 26)->nullable();
            $t->string('type', 16)->default('gift');
            $t->integer('amount_minor');
            $t->integer('balance_minor');
            $t->string('currency', 3)->default('VND');
            $t->string('state', 16)->default('active');
            $t->datetime('expires_at')->nullable();
            $t->datetime('created_at')->nullable();
            $t->primary('id');
            $t->unique('code');
            $t->index('owner_customer_id');
            $t->index(['state', 'type']);
        });
        Schema::create('gift_credit_ledger', function (Blueprint $t) {
            $t->string('id', 26);
            $t->string('credit_id', 26);
            $t->string('order_id', 32)->nullable();
            $t->integer('delta_minor');
            $t->integer('balance_after_minor');
            $t->string('reason', 64)->default('');
            $t->string('idempotency_key', 128);
            $t->datetime('created_at')->nullable();
            $t->primary('id');
            $t->unique('idempotency_key');
            $t->index('credit_id');
            $t->index('order_id');
        });
    }

    public function down(): void
    {
        Schema::drop('gift_credit_ledger');
        Schema::drop('gift_credits');
    }
};
