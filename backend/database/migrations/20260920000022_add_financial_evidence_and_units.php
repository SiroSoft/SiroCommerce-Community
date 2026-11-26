<?php

declare(strict_types=1);

use Siro\Core\DB\Blueprint;
use Siro\Core\Schema;

return new class {
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $t): void {
            $t->integer('minor_unit')->default(0);
            $t->integer('tax_inclusive')->default(0);
            $t->text('tax_evidence_json')->nullable();
        });
        Schema::table('payments', function (Blueprint $t): void { $t->integer('minor_unit')->default(0); });
        Schema::table('refunds', function (Blueprint $t): void {
            $t->string('currency', 3)->default('VND'); $t->integer('minor_unit')->default(0);
            $t->integer('tax_minor')->default(0); $t->text('evidence_json')->nullable();
        });
        Schema::table('invoices', function (Blueprint $t): void {
            $t->integer('minor_unit')->default(0); $t->integer('subtotal_minor')->default(0);
            $t->integer('discount_minor')->default(0); $t->integer('shipping_minor')->default(0);
            $t->integer('tax_minor')->default(0);
        });
        Schema::table('credit_memos', function (Blueprint $t): void {
            $t->integer('minor_unit')->default(0); $t->integer('tax_minor')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('credit_memos', function (Blueprint $t): void { $t->dropColumn('tax_minor'); $t->dropColumn('minor_unit'); });
        Schema::table('invoices', function (Blueprint $t): void { $t->dropColumn('tax_minor'); $t->dropColumn('shipping_minor'); $t->dropColumn('discount_minor'); $t->dropColumn('subtotal_minor'); $t->dropColumn('minor_unit'); });
        Schema::table('refunds', function (Blueprint $t): void { $t->dropColumn('evidence_json'); $t->dropColumn('tax_minor'); $t->dropColumn('minor_unit'); $t->dropColumn('currency'); });
        Schema::table('payments', function (Blueprint $t): void { $t->dropColumn('minor_unit'); });
        Schema::table('orders', function (Blueprint $t): void { $t->dropColumn('tax_evidence_json'); $t->dropColumn('tax_inclusive'); $t->dropColumn('minor_unit'); });
    }
};
