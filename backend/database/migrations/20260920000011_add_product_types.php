<?php

declare(strict_types=1);

use Siro\Core\DB\Blueprint;
use Siro\Core\Schema;

return new class {
    public function up(): void
    {
        Schema::table('products', function (Blueprint $t) {
            $t->string('type', 32)->default('simple');
            $t->index('type');
        });
        Schema::create('product_components', function (Blueprint $t) {
            $t->string('id', 26);
            $t->string('product_id', 26);
            $t->string('component_variant_id', 26);
            $t->integer('qty')->default(1);
            $t->primary('id');
            $t->unique(['product_id', 'component_variant_id']);
            $t->index('product_id');
            $t->index('component_variant_id');
        });
        Schema::create('digital_assets', function (Blueprint $t) {
            $t->string('id', 26);
            $t->string('variant_id', 26);
            $t->string('name', 255);
            $t->string('url', 1024);
            $t->string('access_key', 128);
            $t->integer('download_limit')->nullable();
            $t->datetime('expires_at')->nullable();
            $t->primary('id');
            $t->unique(['variant_id', 'access_key']);
            $t->index('variant_id');
        });
    }

    public function down(): void
    {
        Schema::drop('digital_assets');
        Schema::drop('product_components');
        Schema::dropColumn('products', 'type');
    }
};
