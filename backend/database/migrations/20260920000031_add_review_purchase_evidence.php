<?php

declare(strict_types=1);

use Siro\Core\DB\Blueprint;
use Siro\Core\Schema;

return new class {
    public function up(): void
    {
        Schema::table('product_reviews', function (Blueprint $t): void {
            $t->integer('verified_purchase')->default(0);
            $t->text('purchase_evidence_json')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('product_reviews', function (Blueprint $t): void {
            $t->dropColumn('purchase_evidence_json');
            $t->dropColumn('verified_purchase');
        });
    }
};
