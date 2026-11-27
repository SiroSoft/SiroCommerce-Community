<?php

declare(strict_types=1);

use Siro\Core\DB\Blueprint;
use Siro\Core\Schema;

return new class {
    public function up(): void
    {
        Schema::table('product_reviews', function (Blueprint $t): void {
            $t->text('images_json')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('product_reviews', function (Blueprint $t): void {
            $t->dropColumn('images_json');
        });
    }
};
