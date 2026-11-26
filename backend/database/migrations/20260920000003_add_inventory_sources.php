<?php

declare(strict_types=1);

use Siro\Core\DB\Blueprint;
use Siro\Core\Schema;

return new class {
    public function up(): void
    {
        // Existing rows become the legacy/default source without data loss.
        Schema::table('inventory_items', function (Blueprint $t) {
            $t->string('source_id', 64)->default('default');
            $t->dropUnique('uq_inventory_items_variant_id');
            $t->unique(['variant_id', 'source_id']);
            $t->index(['source_id', 'variant_id']);
        });
        Schema::table('stock_ledger', function (Blueprint $t) {
            $t->string('source_id', 64)->default('default');
            $t->index(['variant_id', 'source_id']);
        });
        Schema::table('reservations', function (Blueprint $t) {
            $t->string('source_id', 64)->default('default');
            $t->index(['variant_id', 'source_id', 'state']);
        });
    }

    public function down(): void
    {
        Schema::table('reservations', function (Blueprint $t) {
            $t->dropIndex('idx_reservations_variant_id_source_id_state');
            $t->dropColumn('source_id');
        });
        Schema::table('stock_ledger', function (Blueprint $t) {
            $t->dropIndex('idx_stock_ledger_variant_id_source_id');
            $t->dropColumn('source_id');
        });
        Schema::table('inventory_items', function (Blueprint $t) {
            $t->dropIndex('idx_inventory_items_source_id_variant_id');
            $t->dropUnique('uq_inventory_items_variant_id_source_id');
            $t->unique('variant_id');
            $t->dropColumn('source_id');
        });
    }
};
