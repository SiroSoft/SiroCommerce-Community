<?php

declare(strict_types=1);

use Siro\Core\DB\Blueprint;
use Siro\Core\Schema;

return new class {
    public function up(): void
    {
        Schema::create('ext_product_review_media', function (Blueprint $t) {
            $t->string('id', 26);
            $t->string('store_id', 64);
            $t->string('channel_id', 64);
            $t->string('review_id', 64);
            $t->string('product_id', 64);
            $t->string('url', 2048);
            $t->integer('sort')->default(0);
            $t->string('status', 16)->default('pending');
            $t->text('moderation_note')->nullable();
            $t->datetime('created_at')->nullable();
            $t->datetime('updated_at')->nullable();
            $t->primary('id');
            $t->unique(['store_id', 'channel_id', 'review_id', 'sort']);
            $t->index(['store_id', 'channel_id', 'product_id', 'status', 'created_at'], 'idx_ext_revmedia_scope_product');
            $t->index(['store_id', 'channel_id', 'status', 'created_at'], 'idx_ext_revmedia_scope_state');
        });
    }

    public function down(): void
    {
        Schema::drop('ext_product_review_media');
    }
};
