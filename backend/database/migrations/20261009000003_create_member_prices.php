<?php
declare(strict_types=1);
use Siro\Core\DB\Blueprint;
use Siro\Core\Schema;
return new class {
    public function up(): void
    {
        Schema::create('member_prices', function (Blueprint $t) {
            $t->string('id', 26);
            $t->string('variant_id', 26);
            $t->string('tier', 32);
            $t->integer('price_minor');
            $t->string('currency', 3)->default('VND');
            $t->datetime('created_at')->nullable();
            $t->datetime('updated_at')->nullable();
            $t->primary('id');
            $t->unique(['variant_id', 'tier', 'currency'], 'uq_member_price');
            $t->index('variant_id');
        });
    }
    public function down(): void
    {
        Schema::drop('member_prices');
    }
};
