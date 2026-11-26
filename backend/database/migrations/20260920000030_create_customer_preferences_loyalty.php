<?php

declare(strict_types=1);

use Siro\Core\DB\Blueprint;
use Siro\Core\Schema;

return new class {
    public function up(): void
    {
        Schema::create('customer_notification_preferences', function (Blueprint $t) {
            $t->string('customer_id', 26);
            $t->integer('transactional_email')->default(1);
            $t->integer('marketing_email')->default(0);
            $t->datetime('updated_at')->nullable();
            $t->primary('customer_id');
        });

        Schema::create('loyalty_accounts', function (Blueprint $t) {
            $t->string('id', 26);
            $t->string('customer_id', 26);
            $t->integer('balance_points')->default(0);
            $t->string('state', 16)->default('active');
            $t->datetime('created_at')->nullable();
            $t->datetime('updated_at')->nullable();
            $t->primary('id');
            $t->unique('customer_id');
        });

        Schema::create('loyalty_ledger', function (Blueprint $t) {
            $t->string('id', 26);
            $t->string('account_id', 26);
            $t->string('customer_id', 26);
            $t->string('operation', 16);
            $t->integer('points');
            $t->string('idempotency_key', 128);
            $t->string('request_fingerprint', 64);
            $t->string('order_id', 26)->nullable();
            $t->datetime('expires_at')->nullable();
            $t->datetime('expired_at')->nullable();
            $t->datetime('created_at')->nullable();
            $t->primary('id');
            $t->unique('idempotency_key');
            $t->unique(['operation', 'order_id']);
            $t->index(['account_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::drop('loyalty_ledger');
        Schema::drop('loyalty_accounts');
        Schema::drop('customer_notification_preferences');
    }
};
