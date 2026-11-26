<?php

declare(strict_types=1);

use Siro\Core\DB\Blueprint;
use Siro\Core\Schema;

return new class {
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $t): void {
            $t->text('promotion_rule_json')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $t): void {
            $t->dropColumn('promotion_rule_json');
        });
    }
};
