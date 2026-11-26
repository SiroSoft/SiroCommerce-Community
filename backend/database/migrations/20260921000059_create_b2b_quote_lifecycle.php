<?php
declare(strict_types=1);

use Siro\Core\DB\Blueprint;
use Siro\Core\Schema;

return new class {
    public function up(): void
    {
        Schema::table('b2b_quotes', function (Blueprint $t): void {
            $t->integer('current_revision')->default(1);
            $t->integer('accepted_by')->nullable();
            $t->datetime('accepted_at')->nullable();
            $t->string('converted_order_id', 26)->nullable();
        });
        Schema::create('b2b_quote_revisions', function (Blueprint $t): void {
            $t->string('id', 26); $t->string('quote_id', 26); $t->integer('revision');
            $t->string('state', 20); $t->text('lines_json'); $t->text('totals_json');
            $t->string('snapshot_hash', 64); $t->integer('created_by'); $t->datetime('created_at')->nullable();
            $t->primary('id'); $t->unique(['quote_id', 'revision']); $t->index('quote_id');
        });
        Schema::create('b2b_quote_transitions', function (Blueprint $t): void {
            $t->string('id', 26); $t->string('quote_id', 26); $t->string('from_state', 20); $t->string('to_state', 20);
            $t->integer('user_id'); $t->string('idempotency_key', 255); $t->string('reason')->default(''); $t->datetime('created_at')->nullable();
            $t->string('result_json')->default('{}'); $t->primary('id'); $t->unique('idempotency_key'); $t->index('quote_id');
        });
        Schema::create('b2b_quote_credit_reservations', function (Blueprint $t): void {
            $t->string('id', 26); $t->string('quote_id', 26); $t->string('company_id', 26); $t->string('currency', 3);
            $t->integer('amount_minor'); $t->string('state', 20)->default('reserved'); $t->string('idempotency_key', 255);
            $t->datetime('created_at')->nullable(); $t->primary('id'); $t->unique('quote_id'); $t->unique('idempotency_key'); $t->index(['company_id', 'state']);
        });
    }

    public function down(): void
    {
        Schema::drop('b2b_quote_credit_reservations'); Schema::drop('b2b_quote_transitions'); Schema::drop('b2b_quote_revisions');
        Schema::table('b2b_quotes', function (Blueprint $t): void { $t->dropColumn('converted_order_id'); $t->dropColumn('accepted_at'); $t->dropColumn('accepted_by'); $t->dropColumn('current_revision'); });
    }
};
