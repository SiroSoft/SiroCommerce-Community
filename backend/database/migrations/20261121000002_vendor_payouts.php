<?php

declare(strict_types=1);

use Siro\Core\DB\Blueprint;
use Siro\Core\Schema;

return new class {
    public function up(): void
    {
        Schema::table('order_lines', function (Blueprint $t): void {
            $t->string('vendor_id', 26)->nullable();
            $t->integer('vendor_commission_minor')->nullable();
            $t->index('vendor_id', 'idx_order_lines_vendor_id');
        });
        Schema::table('vendors', function (Blueprint $t): void {
            $t->string('owner_customer_id', 64)->nullable();
            $t->index('owner_customer_id', 'idx_vendors_owner_customer_id');
        });
        Schema::create('vendor_ledger', function (Blueprint $t): void {
            $t->string('id', 26);
            $t->string('vendor_id', 26);
            $t->string('order_id', 32)->nullable();
            $t->string('order_line_id', 26)->nullable();
            $t->string('type', 16);
            $t->integer('gross_minor')->default(0);
            $t->integer('commission_minor')->default(0);
            $t->integer('net_minor')->default(0);
            $t->string('currency', 3)->default('VND');
            $t->string('state', 16)->default('pending');
            $t->string('payout_id', 26)->nullable();
            $t->string('idempotency_key', 191);
            $t->timestamp('created_at')->useCurrent();
            $t->primary('id');
            $t->unique('idempotency_key', 'uq_vendor_ledger_idempotency_key');
            $t->index(['vendor_id', 'state'], 'idx_vendor_ledger_vendor_state');
            $t->index('order_id', 'idx_vendor_ledger_order_id');
            $t->index('order_line_id', 'idx_vendor_ledger_order_line_id');
            $t->index('payout_id', 'idx_vendor_ledger_payout_id');
        });
        Schema::create('vendor_payouts', function (Blueprint $t): void {
            $t->string('id', 26);
            $t->string('vendor_id', 26);
            $t->integer('amount_minor')->default(0);
            $t->string('currency', 3)->default('VND');
            $t->string('state', 16)->default('pending');
            $t->datetime('period_start');
            $t->datetime('period_end');
            $t->integer('entries_count')->default(0);
            $t->string('idempotency_key', 191);
            $t->timestamp('created_at')->useCurrent();
            $t->timestamp('paid_at')->nullable();
            $t->timestamp('cancelled_at')->nullable();
            $t->primary('id');
            $t->unique('idempotency_key', 'uq_vendor_payouts_idempotency_key');
            $t->index(['vendor_id', 'state'], 'idx_vendor_payouts_vendor_state');
        });
    }

    public function down(): void
    {
        Schema::drop('vendor_payouts');
        Schema::drop('vendor_ledger');
        Schema::table('vendors', function (Blueprint $t): void {
            $t->dropIndex('idx_vendors_owner_customer_id');
            $t->dropColumn('owner_customer_id');
        });
        Schema::table('order_lines', function (Blueprint $t): void {
            $t->dropIndex('idx_order_lines_vendor_id');
            $t->dropColumn('vendor_commission_minor');
            $t->dropColumn('vendor_id');
        });
    }
};
