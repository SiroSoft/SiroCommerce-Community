<?php

declare(strict_types=1);

use Siro\Core\DB\Blueprint;
use Siro\Core\Schema;

return new class {
    public function up(): void
    {
        Schema::create('ext_size_guide_charts', function (Blueprint $t) {
            $t->string('id', 26);
            $t->string('store_id', 64);
            $t->string('channel_id', 64);
            $t->string('category_slug', 160);
            $t->string('title', 120);
            $t->json('columns_json');
            $t->json('rows_json');
            $t->string('note', 300)->nullable();
            $t->boolean('active')->default(true);
            $t->datetime('created_at')->nullable();
            $t->datetime('updated_at')->nullable();
            $t->primary('id');
            $t->unique(['store_id', 'channel_id', 'category_slug']);
            $t->index(['store_id', 'channel_id', 'active', 'category_slug'], 'idx_ext_size_guide_scope_active');
        });
    }

    public function down(): void
    {
        Schema::drop('ext_size_guide_charts');
    }
};
