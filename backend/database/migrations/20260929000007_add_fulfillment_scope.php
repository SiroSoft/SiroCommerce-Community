<?php

declare(strict_types=1);

use Siro\Core\DB\Blueprint;
use Siro\Core\Schema;

return new class {
    public function up(): void
    {
        Schema::table('reservations', function (Blueprint $t): void {
            $t->string('channel_id', 64)->nullable();
            $t->string('locale', 10)->nullable();
            $t->string('currency', 3)->nullable();
            $t->index(['store_id', 'channel_id', 'state'], 'idx_reservations_commerce_scope');
        });
    }

    public function down(): void
    {
        Schema::table('reservations', function (Blueprint $t): void {
            $t->dropIndex('idx_reservations_commerce_scope');
            $t->dropColumn('currency');
            $t->dropColumn('locale');
            $t->dropColumn('channel_id');
        });
    }
};
