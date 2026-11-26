<?php
declare(strict_types=1);
use Siro\Core\DB\Blueprint;
use Siro\Core\Schema;
return new class {
    public function up(): void
    {
        Schema::table('company_catalogs', function (Blueprint $t): void { $t->string('scope', 16)->default('company'); $t->index(['scope', 'state']); });
        Schema::table('b2b_quotes', function (Blueprint $t): void { $t->string('purchase_order_number', 80)->nullable(); $t->integer('payment_terms_days')->default(0); });
    }
    public function down(): void
    {
        Schema::table('b2b_quotes', function (Blueprint $t): void { $t->dropColumn('payment_terms_days'); $t->dropColumn('purchase_order_number'); });
        Schema::table('company_catalogs', function (Blueprint $t): void { $t->dropIndex('idx_company_catalogs_scope_state'); $t->dropColumn('scope'); });
    }
};
