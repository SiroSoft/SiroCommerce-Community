<?php

declare(strict_types=1);

use Siro\Core\DB\Blueprint;
use Siro\Core\Schema;

return new class {
    public function up(): void
    {
        Schema::table('inventory_adjustments', function (Blueprint $t): void {
            $t->string('idempotency_key', 128)->nullable();
            $t->unique('idempotency_key', 'uq_inventory_adjustments_idempotency_key');
        });
    }

    public function down(): void
    {
        Schema::table('inventory_adjustments', function (Blueprint $t): void {
            $t->dropUnique('uq_inventory_adjustments_idempotency_key');
            $t->dropColumn('idempotency_key');
        });
    }
};
