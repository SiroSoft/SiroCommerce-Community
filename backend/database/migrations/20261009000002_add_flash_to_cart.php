<?php
declare(strict_types=1);
use Siro\Core\DB\Blueprint;
use Siro\Core\Schema;
return new class {
    public function up(): void
    {
        Schema::table('cart_lines', function (Blueprint $t): void {
            try { $t->string('flash_sale_id', 26)->nullable(); } catch (\Throwable $e) {}
        });
    }
    public function down(): void
    {
        Schema::table('cart_lines', function (Blueprint $t): void {
            try { $t->dropColumn('flash_sale_id'); } catch (\Throwable $e) {}
        });
    }
};
