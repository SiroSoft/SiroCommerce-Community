<?php

declare(strict_types=1);

use Siro\Core\DB\Blueprint;
use Siro\Core\Schema;

return new class {
    public function up(): void
    {
        Schema::table('products', function (Blueprint $t) {
            $t->string('bundle_pricing_mode', 32)->default('component_sum');
            $t->integer('bundle_price_minor')->nullable();
        });
        Schema::create('digital_downloads', function (Blueprint $t) {
            $t->string('id', 26);
            $t->string('asset_id', 26);
            $t->string('order_id', 26);
            $t->string('token_hash', 128);
            $t->integer('download_count')->default(0);
            $t->integer('download_limit')->nullable();
            $t->datetime('expires_at')->nullable();
            $t->datetime('last_downloaded_at')->nullable();
            $t->primary('id');
            $t->unique('token_hash');
            $t->index(['asset_id', 'order_id']);
        });
    }

    public function down(): void
    {
        Schema::drop('digital_downloads');
        Schema::dropColumn('products', 'bundle_price_minor');
        Schema::dropColumn('products', 'bundle_pricing_mode');
    }
};
