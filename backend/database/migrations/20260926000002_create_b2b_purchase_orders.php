<?php
declare(strict_types=1);

use Siro\Core\DB\Blueprint;
use Siro\Core\Schema;

return new class {
    public function up(): void
    {
        Schema::create('b2b_purchase_orders', function (Blueprint $t): void {
            $t->string('id', 26); $t->string('company_id', 26); $t->string('contract_id', 26)->nullable();
            $t->string('po_number', 80); $t->string('currency', 3); $t->integer('total_minor');
            $t->string('state', 20)->default('draft'); $t->integer('created_by'); $t->integer('approved_by')->nullable();
            $t->string('idempotency_key', 255); $t->text('evidence_json'); $t->timestamps();
            $t->primary('id'); $t->unique(['company_id', 'po_number']); $t->unique('idempotency_key');
            $t->index(['company_id', 'state']);
        });
        Schema::create('b2b_purchase_order_transitions', function (Blueprint $t): void {
            $t->string('id', 26); $t->string('purchase_order_id', 26); $t->string('from_state', 20);
            $t->string('to_state', 20); $t->integer('user_id'); $t->string('idempotency_key', 255); $t->string('reason')->nullable(); $t->timestamps();
            $t->primary('id'); $t->unique('idempotency_key'); $t->index(['purchase_order_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::drop('b2b_purchase_order_transitions'); Schema::drop('b2b_purchase_orders');
    }
};
