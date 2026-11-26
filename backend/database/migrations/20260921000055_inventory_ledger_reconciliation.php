<?php

declare(strict_types=1);

use Siro\Core\DB\Blueprint;
use Siro\Core\Schema;

return new class {
    public function up(): void
    {
        // Keeps per-source reservation ledger reconciliation ordered and index-backed.
        Schema::table('stock_ledger', function (Blueprint $t): void {
            $t->index(['variant_id', 'source_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('stock_ledger', function (Blueprint $t): void {
            $t->dropIndex('idx_stock_ledger_variant_id_source_id_created_at');
        });
    }
};
