<?php

declare(strict_types=1);

use Siro\Core\DB\Blueprint;
use Siro\Core\Schema;

return new class {
    public function up(): void
    {
        Schema::table('addresses', function (Blueprint $t): void {
            $t->string('country', 2)->default('VN');
            $t->string('region', 64)->nullable();
            $t->string('postal_code', 32)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('addresses', function (Blueprint $t): void {
            $t->dropColumn('postal_code');
            $t->dropColumn('region');
            $t->dropColumn('country');
        });
    }
};
