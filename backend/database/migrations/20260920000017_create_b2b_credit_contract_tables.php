<?php

declare(strict_types=1);

use Siro\Core\DB\Blueprint;
use Siro\Core\Schema;

return new class {
    public function up(): void
    {
        Schema::create('company_credit_accounts', function (Blueprint $t) {
            $t->string('id', 26); $t->string('company_id', 26); $t->string('currency', 3)->default('VND');
            $t->integer('limit_minor')->default(0); $t->string('status', 20)->default('pending'); $t->timestamps();
            $t->primary('id'); $t->unique('company_id');
        });
        Schema::create('company_contracts', function (Blueprint $t) {
            $t->string('id', 26); $t->string('company_id', 26); $t->string('contract_number', 80);
            $t->string('status', 20)->default('pending'); $t->integer('credit_limit_minor')->default(0);
            $t->string('currency', 3)->default('VND'); $t->string('starts_at', 32)->nullable(); $t->string('ends_at', 32)->nullable(); $t->timestamps();
            $t->primary('id'); $t->unique('contract_number'); $t->index('company_id');
        });
        Schema::create('company_contract_approvals', function (Blueprint $t) {
            $t->string('id', 26); $t->string('contract_id', 26); $t->string('company_id', 26); $t->integer('user_id');
            $t->string('status', 20)->default('approved'); $t->string('idempotency_key', 255); $t->timestamps();
            $t->primary('id'); $t->unique('idempotency_key'); $t->unique(['contract_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::drop('company_contract_approvals'); Schema::drop('company_contracts'); Schema::drop('company_credit_accounts');
    }
};
