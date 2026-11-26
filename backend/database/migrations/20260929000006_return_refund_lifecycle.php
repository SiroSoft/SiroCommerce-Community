<?php
declare(strict_types=1);
use Siro\Core\DB\Blueprint;
use Siro\Core\Schema;
return new class {
    public function up(): void { Schema::table('return_requests', function (Blueprint $t) { $t->string('refund_payment_id', 26)->nullable(); $t->integer('refund_amount_minor')->nullable(); $t->string('refund_currency', 3)->nullable(); $t->string('refund_state', 32)->nullable(); $t->string('refund_idempotency_key', 128)->nullable(); $t->string('refund_error')->nullable(); $t->index(['refund_payment_id', 'refund_state']); }); }
    public function down(): void { Schema::table('return_requests', function (Blueprint $t) { $t->dropIndex('idx_return_requests_refund_payment_id_refund_state'); $t->dropColumn('refund_error'); $t->dropColumn('refund_idempotency_key'); $t->dropColumn('refund_state'); $t->dropColumn('refund_currency'); $t->dropColumn('refund_amount_minor'); $t->dropColumn('refund_payment_id'); }); }
};
