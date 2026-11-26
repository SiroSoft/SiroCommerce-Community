<?php
declare(strict_types=1);

use Siro\Core\DB\Blueprint;
use Siro\Core\Schema;

return new class {
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $t) {
            $t->string('id', 26); $t->string('number', 32); $t->string('order_id', 26); $t->string('payment_id', 26);
            $t->string('state', 32)->default('draft'); $t->string('currency', 3); $t->integer('amount_minor');
            $t->text('evidence_json'); $t->string('idempotency_key', 128); $t->datetime('issued_at')->nullable(); $t->datetime('created_at')->nullable();
            $t->primary('id'); $t->unique('number'); $t->unique('idempotency_key'); $t->index(['order_id', 'payment_id']);
        });
        Schema::create('credit_memos', function (Blueprint $t) {
            $t->string('id', 26); $t->string('number', 32); $t->string('invoice_id', 26); $t->string('order_id', 26); $t->string('payment_id', 26); $t->string('refund_id', 26);
            $t->string('state', 32)->default('issued'); $t->string('currency', 3); $t->integer('amount_minor'); $t->string('reason')->default('');
            $t->string('idempotency_key', 128); $t->text('evidence_json'); $t->datetime('created_at')->nullable();
            $t->primary('id'); $t->unique('number'); $t->unique('idempotency_key'); $t->unique('refund_id'); $t->index(['order_id', 'invoice_id']);
        });
    }
    public function down(): void { Schema::drop('credit_memos'); Schema::drop('invoices'); }
};
