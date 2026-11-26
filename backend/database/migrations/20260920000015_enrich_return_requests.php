<?php
declare(strict_types=1);
use Siro\Core\DB\Blueprint;
use Siro\Core\Schema;
return new class {
    public function up(): void { Schema::table('return_requests', function (Blueprint $t) { $t->string('disposition', 32)->default('refund'); $t->string('inspection_note')->nullable(); }); Schema::table('return_lines', function (Blueprint $t) { $t->string('disposition', 32)->default('restock'); $t->string('condition', 32)->default('good'); }); }
    public function down(): void
    {
        Schema::table('return_lines', function (Blueprint $t) { $t->dropColumn('condition'); $t->dropColumn('disposition'); });
        Schema::table('return_requests', function (Blueprint $t) { $t->dropColumn('inspection_note'); $t->dropColumn('disposition'); });
    }
};
