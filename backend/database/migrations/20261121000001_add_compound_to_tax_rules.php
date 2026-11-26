<?php

declare(strict_types=1);

use Siro\Core\DB\Blueprint;
use Siro\Core\Schema;

return new class {
    public function up(): void
    {
        Schema::table('tax_rules', function (Blueprint $t): void {
            $t->integer('compound')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('tax_rules', function (Blueprint $t): void {
            $t->dropColumn('compound');
        });
    }
};
