<?php
declare(strict_types=1);

use Siro\Core\DB\Blueprint;
use Siro\Core\Schema;

return new class {
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $t): void {
            $t->integer('refunded_amount_minor')->default(0);
            $t->index(['state', 'refunded_amount_minor']);
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $t): void {
            $t->dropIndex('idx_payments_state_refunded_amount_minor'); $t->dropColumn('refunded_amount_minor');
        });
    }
};
