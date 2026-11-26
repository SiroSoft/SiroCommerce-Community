<?php

declare(strict_types=1);

use Siro\Core\DB\Blueprint;
use Siro\Core\Schema;

return new class {
    public function up(): void
    {
        Schema::create('smart_affinities', function (Blueprint $t) {
            $t->string('id', 26);
            $t->string('product_a', 26);
            $t->string('product_b', 26);
            $t->integer('orders')->default(0);
            $t->integer('score')->default(0);
            $t->datetime('computed_at')->nullable();
            $t->primary('id');
            $t->unique(['product_a', 'product_b']);
            $t->index('product_a');
        });
        Schema::create('smart_search_terms', function (Blueprint $t) {
            $t->string('id', 26);
            $t->string('query', 64);
            $t->integer('submits')->default(0);
            $t->integer('clicks')->default(0);
            $t->datetime('computed_at')->nullable();
            $t->primary('id');
            $t->unique('query');
        });
        Schema::create('smart_review_rollups', function (Blueprint $t) {
            $t->string('id', 26);
            $t->string('product_id', 26);
            $t->float('rating_avg')->default(0);
            $t->integer('rating_count')->default(0);
            $t->integer('pos_count')->default(0);
            $t->integer('neg_count')->default(0);
            // MySQL forbids defaults on TEXT/BLOB/JSON: nullable instead.
            // Readers coalesce NULL to []/{} (SmartDiscoveryController).
            $t->text('aspects_json')->nullable();
            $t->text('excerpts_json')->nullable();
            $t->datetime('computed_at')->nullable();
            $t->primary('id');
            $t->unique('product_id');
        });
    }

    public function down(): void
    {
        Schema::drop('smart_review_rollups');
        Schema::drop('smart_search_terms');
        Schema::drop('smart_affinities');
    }
};
