<?php
declare(strict_types=1);
use Siro\Core\DB\Blueprint;
use Siro\Core\Schema;
return new class {
    public function up(): void
    {
        Schema::table('reservations', function (Blueprint $t): void {
            $t->string('allocation_key', 128)->nullable();
            $t->index(['allocation_key', 'state']);
        });
    }
    public function down(): void
    {
        Schema::table('reservations', function (Blueprint $t): void {
            $t->dropIndex('idx_reservations_allocation_key_state');
            $t->dropColumn('allocation_key');
        });
    }
};
