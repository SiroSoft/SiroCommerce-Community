<?php

declare(strict_types=1);

use Siro\Core\DB\Blueprint;
use Siro\Core\Schema;

return new class {
    public function up(): void
    {
        Schema::table('fulfillment_lines', function (Blueprint $t): void {
            $t->string('source_id', 26)->default('default');
            $t->string('substitution_variant_id', 26)->nullable();
            $t->index(['source_id', 'order_line_id']);
        });
    }

    public function down(): void
    {
        Schema::table('fulfillment_lines', function (Blueprint $t): void { $t->dropIndex('idx_fulfillment_lines_source_id_order_line_id'); $t->dropColumn('substitution_variant_id'); $t->dropColumn('source_id'); });
    }
};
