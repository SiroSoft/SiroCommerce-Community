<?php

declare(strict_types=1);

use Siro\Core\DB\Blueprint;
use Siro\Core\Schema;

return new class {
    public function up(): void
    {
        Schema::create('replenishment_hints', function (Blueprint $t) {
            $t->string('id', 26);
            $t->string('variant_id', 26);
            $t->string('product_id', 26)->nullable();
            $t->integer('sold_7')->default(0);
            $t->integer('sold_30')->default(0);
            $t->integer('on_hand')->default(0);
            $t->integer('available')->default(0);
            $t->integer('days_of_cover')->nullable();
            $t->integer('suggested_qty')->default(0);
            $t->datetime('computed_at')->nullable();
            $t->primary('id');
            $t->unique('variant_id');
            $t->index('product_id');
        });
    }

    public function down(): void
    {
        Schema::drop('replenishment_hints');
    }
};
