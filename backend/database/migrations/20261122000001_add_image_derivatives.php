<?php

declare(strict_types=1);

use Siro\Core\DB\Blueprint;
use Siro\Core\Schema;

return new class {
    public function up(): void
    {
        Schema::table('product_images', function (Blueprint $t) {
            $t->json('variants_json')->nullable();
            $t->text('placeholder')->nullable();
        });
        Schema::table('media_assets', function (Blueprint $t) {
            $t->json('variants_json')->nullable();
            $t->text('placeholder')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropColumn('product_images', 'variants_json');
        Schema::dropColumn('product_images', 'placeholder');
        Schema::dropColumn('media_assets', 'variants_json');
        Schema::dropColumn('media_assets', 'placeholder');
    }
};
