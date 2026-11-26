<?php

declare(strict_types=1);

use Siro\Core\DB\Blueprint;
use Siro\Core\Schema;

return new class {
    public function up(): void
    {
        Schema::table('order_lines', function (Blueprint $t): void {
            $t->string('product_type', 32)->default('simple');
            $t->text('components_json')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('order_lines', function (Blueprint $t): void {
            $t->dropColumn('components_json');
            $t->dropColumn('product_type');
        });
    }
};
