<?php

declare(strict_types=1);

use Siro\Core\DB\Blueprint;
use Siro\Core\Schema;

return new class {
    public function up(): void
    {
        Schema::create('stores', function (Blueprint $t) {
            $t->string('id', 26); $t->string('code', 64); $t->string('name', 128);
            $t->string('status', 20)->default('active'); $t->timestamps();
            $t->primary('id'); $t->unique('code');
        });
        Schema::create('channels', function (Blueprint $t) {
            $t->string('id', 26); $t->string('store_id', 26); $t->string('code', 64);
            $t->string('name', 128); $t->string('status', 20)->default('active'); $t->timestamps();
            $t->primary('id'); $t->unique(['store_id', 'code']); $t->index('store_id');
        });
        Schema::create('companies', function (Blueprint $t) {
            $t->string('id', 26); $t->string('name', 160); $t->string('status', 20)->default('active'); $t->timestamps();
            $t->primary('id');
        });
        Schema::create('company_members', function (Blueprint $t) {
            $t->string('id', 26); $t->string('company_id', 26); $t->integer('user_id');
            $t->string('role', 32)->default('buyer'); $t->string('status', 20)->default('active'); $t->timestamps();
            $t->primary('id'); $t->unique(['company_id', 'user_id']); $t->index('user_id');
        });
        Schema::create('company_approval_scopes', function (Blueprint $t) {
            $t->string('id', 26); $t->string('company_id', 26); $t->string('scope', 64);
            $t->string('channel_id', 26)->nullable(); $t->integer('approval_limit_minor')->nullable();
            $t->integer('active')->default(1); $t->timestamps();
            $t->primary('id'); $t->unique(['company_id', 'scope', 'channel_id']); $t->index('company_id');
        });
        Schema::create('company_orders', function (Blueprint $t) {
            $t->string('order_id', 26); $t->string('company_id', 26); $t->string('channel_id', 26)->nullable();
            $t->primary('order_id'); $t->index(['company_id', 'channel_id']);
        });
        Schema::create('company_order_approvals', function (Blueprint $t) {
            $t->string('id', 26); $t->string('company_id', 26); $t->string('order_id', 26); $t->integer('user_id');
            $t->string('scope', 64); $t->string('status', 20)->default('approved'); $t->string('idempotency_key', 255);
            $t->timestamps(); $t->primary('id'); $t->unique('idempotency_key'); $t->unique(['company_id', 'order_id', 'scope']);
        });
    }

    public function down(): void
    {
        Schema::drop('company_order_approvals'); Schema::drop('company_orders'); Schema::drop('company_approval_scopes');
        Schema::drop('company_members'); Schema::drop('companies'); Schema::drop('channels'); Schema::drop('stores');
    }
};
