<?php

declare(strict_types=1);

use Siro\Core\DB\Blueprint;
use Siro\Core\Schema;

return new class {
    public function up(): void
    {
        Schema::table('carts', function (Blueprint $t): void {
            $t->string('access_token_hash', 64)->nullable();
            $t->index('access_token_hash');
        });
    }

    public function down(): void
    {
        Schema::table('carts', function (Blueprint $t): void {
            $t->dropIndex('idx_carts_access_token_hash');
            $t->dropColumn('access_token_hash');
        });
    }
};
