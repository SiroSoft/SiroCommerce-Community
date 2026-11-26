<?php
declare(strict_types=1);

use Siro\Core\DB\Blueprint;
use Siro\Core\Schema;

return new class {
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $t): void { $t->integer('captured_amount_minor')->default(0); });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $t): void { $t->dropColumn('captured_amount_minor'); });
    }
};
