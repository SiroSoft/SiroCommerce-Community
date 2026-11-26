<?php
declare(strict_types=1);

use Siro\Core\DB\Blueprint;
use Siro\Core\Schema;

return new class {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t): void {
            $t->text('tenant_ids')->nullable();
            $t->text('seller_ids')->nullable();
            $t->text('site_ids')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $t): void {
            $t->dropColumn('tenant_ids');
            $t->dropColumn('seller_ids');
            $t->dropColumn('site_ids');
        });
    }
};
