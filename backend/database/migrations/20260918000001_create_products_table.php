<?php

declare(strict_types=1);

use Siro\Core\DB\Blueprint;
use Siro\Core\Schema;

return new class {
    public function up(): void
    {
        Schema::create('products', function (Blueprint $t) {
            $t->string('id', 26);
            $t->string('name');
            $t->string('slug');
            $t->string('brand_id', 26)->nullable();
            $t->string('status', 20)->default('draft');
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::drop('products');
    }
};
