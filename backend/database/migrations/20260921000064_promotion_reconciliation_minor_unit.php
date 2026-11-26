<?php
declare(strict_types=1);

use Siro\Core\DB\Blueprint;
use Siro\Core\Schema;

return new class {
    public function up(): void
    {
        Schema::table('promotion_usage_reconciliations', function (Blueprint $t): void {
            $t->integer('minor_unit')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('promotion_usage_reconciliations', function (Blueprint $t): void {
            $t->dropColumn('minor_unit');
        });
    }
};
