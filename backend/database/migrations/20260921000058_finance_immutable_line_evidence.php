<?php
declare(strict_types=1);
use Siro\Core\DB\Blueprint;
use Siro\Core\Schema;
return new class {
    public function up(): void
    {
        Schema::table('refunds', function (Blueprint $t): void { $t->string('idempotency_key', 128)->nullable(); $t->unique('idempotency_key'); });
        Schema::create('invoice_lines', function (Blueprint $t): void { $t->string('id', 26); $t->string('invoice_id', 26); $t->string('source_line_id', 26)->nullable(); $t->string('sku', 64); $t->string('name'); $t->integer('qty')->default(0); $t->integer('unit_price_minor')->default(0); $t->integer('amount_minor')->default(0); $t->integer('tax_minor')->default(0); $t->primary('id'); $t->index('invoice_id'); });
        Schema::create('invoice_taxes', function (Blueprint $t): void { $t->string('id', 26); $t->string('invoice_id', 26); $t->string('code', 64)->default(''); $t->string('rate', 32)->default(''); $t->integer('taxable_minor')->default(0); $t->integer('tax_minor')->default(0); $t->text('evidence_json')->nullable(); $t->primary('id'); $t->index('invoice_id'); });
        Schema::create('credit_memo_lines', function (Blueprint $t): void { $t->string('id', 26); $t->string('credit_memo_id', 26); $t->string('source_line_id', 26)->nullable(); $t->string('sku', 64); $t->string('name'); $t->integer('amount_minor')->default(0); $t->integer('tax_minor')->default(0); $t->primary('id'); $t->index('credit_memo_id'); });
        Schema::create('credit_memo_taxes', function (Blueprint $t): void { $t->string('id', 26); $t->string('credit_memo_id', 26); $t->string('code', 64)->default(''); $t->string('rate', 32)->default(''); $t->integer('taxable_minor')->default(0); $t->integer('tax_minor')->default(0); $t->text('evidence_json')->nullable(); $t->primary('id'); $t->index('credit_memo_id'); });
    }
    public function down(): void { Schema::drop('credit_memo_taxes'); Schema::drop('credit_memo_lines'); Schema::drop('invoice_taxes'); Schema::drop('invoice_lines'); Schema::table('refunds', function (Blueprint $t): void { $t->dropUnique('uq_refunds_idempotency_key'); $t->dropColumn('idempotency_key'); }); }
};
