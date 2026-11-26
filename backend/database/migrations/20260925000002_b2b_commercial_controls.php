<?php
declare(strict_types=1);

use Siro\Core\DB\Blueprint;
use Siro\Core\Schema;

return new class {
    public function up(): void
    {
        Schema::create('b2b_company_groups', function (Blueprint $t): void {
            $t->string('id', 26); $t->string('company_id', 26); $t->string('code', 64); $t->string('name', 128); $t->string('state', 16)->default('active');
            $t->primary('id'); $t->unique(['company_id', 'code']); $t->index('company_id');
        });
        Schema::create('b2b_company_group_members', function (Blueprint $t): void {
            $t->string('id', 26); $t->string('group_id', 26); $t->integer('user_id'); $t->string('state', 16)->default('active');
            $t->primary('id'); $t->unique(['group_id', 'user_id']); $t->index('user_id');
        });
        Schema::create('b2b_company_role_policies', function (Blueprint $t): void {
            $t->string('id', 26); $t->string('company_id', 26); $t->string('role', 32); $t->string('policy_json'); $t->integer('approval_limit_minor')->nullable(); $t->string('state', 16)->default('active');
            $t->primary('id'); $t->unique(['company_id', 'role']);
        });
        Schema::create('b2b_customer_price_lists', function (Blueprint $t): void {
            $t->string('id', 26); $t->string('company_id', 26); $t->string('code', 64); $t->string('currency', 3); $t->string('state', 16)->default('active');
            $t->primary('id'); $t->unique(['company_id', 'code']); $t->index(['company_id', 'state']);
        });
        Schema::create('b2b_customer_price_tiers', function (Blueprint $t): void {
            $t->string('id', 26); $t->string('price_list_id', 26); $t->string('variant_id', 26); $t->integer('minimum_qty'); $t->integer('unit_price_minor');
            $t->primary('id'); $t->unique(['price_list_id', 'variant_id', 'minimum_qty']); $t->index(['variant_id', 'minimum_qty']);
        });
        Schema::create('b2b_company_terms', function (Blueprint $t): void {
            $t->string('company_id', 26); $t->integer('minimum_order_minor')->default(0); $t->integer('minimum_order_qty')->default(0); $t->integer('credit_limit_minor')->default(0); $t->integer('payment_terms_days')->default(0); $t->string('currency', 3); $t->string('state', 16)->default('active');
            $t->primary('company_id');
        });
        Schema::create('b2b_audit_events', function (Blueprint $t): void {
            $t->string('id', 26); $t->string('company_id', 26); $t->string('subject_type', 32); $t->string('subject_id', 26); $t->string('event', 64); $t->integer('actor_id'); $t->string('idempotency_key', 255); $t->text('payload_json'); $t->datetime('created_at')->nullable();
            $t->primary('id'); $t->unique('idempotency_key'); $t->index(['company_id', 'subject_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::drop('b2b_audit_events'); Schema::drop('b2b_company_terms'); Schema::drop('b2b_customer_price_tiers'); Schema::drop('b2b_customer_price_lists'); Schema::drop('b2b_company_role_policies'); Schema::drop('b2b_company_group_members'); Schema::drop('b2b_company_groups');
    }
};
