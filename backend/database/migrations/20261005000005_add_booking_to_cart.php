<?php

declare(strict_types=1);

use Siro\Core\DB\Blueprint;
use Siro\Core\Schema;

return new class {
    public function up(): void
    {
        Schema::table('cart_lines', function (Blueprint $t): void {
            try { $t->string('slot_start', 32)->nullable(); } catch (\Throwable $e) {}
            try { $t->string('booking_slot_id', 26)->nullable(); } catch (\Throwable $e) {}
        });
    }

    public function down(): void
    {
        Schema::table('cart_lines', function (Blueprint $t): void {
            try { $t->dropColumn('booking_slot_id'); } catch (\Throwable $e) {}
            try { $t->dropColumn('slot_start'); } catch (\Throwable $e) {}
        });
    }
};
