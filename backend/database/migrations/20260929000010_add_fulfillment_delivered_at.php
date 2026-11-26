<?php

declare(strict_types=1);

use Siro\Core\DB\Blueprint;
use Siro\Core\Schema;

return new class {
    public function up(): void
    {
        Schema::table('fulfillment_orders', function (Blueprint $t): void { $t->datetime('delivered_at')->nullable(); });
    }

    public function down(): void
    {
        Schema::table('fulfillment_orders', function (Blueprint $t): void { $t->dropColumn('delivered_at'); });
    }
};
