<?php

declare(strict_types=1);

use Siro\Core\DB\Blueprint;
use Siro\Core\Schema;

return new class {
    public function up(): void
    {
        Schema::table('vendor_payouts', function (Blueprint $t): void {
            $t->datetime('failed_at')->nullable();
            $t->datetime('returned_at')->nullable();
            $t->string('provider_reference', 191)->nullable();
            $t->string('failure_reason', 191)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('vendor_payouts', function (Blueprint $t): void {
            $t->dropColumn('failure_reason');
            $t->dropColumn('provider_reference');
            $t->dropColumn('returned_at');
            $t->dropColumn('failed_at');
        });
    }
};
