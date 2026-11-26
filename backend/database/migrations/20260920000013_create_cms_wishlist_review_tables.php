<?php

declare(strict_types=1);

use Siro\Core\DB\Blueprint;
use Siro\Core\Schema;

return new class {
    public function up(): void
    {
        Schema::create('cms_pages', function (Blueprint $t) {
            $t->string('id', 26);
            $t->string('slug', 160);
            $t->string('title', 255);
            $t->text('body')->nullable();
            $t->string('status', 20)->default('draft');
            $t->string('locale', 10)->default('en');
            $t->datetime('published_at')->nullable();
            $t->timestamps();
            $t->primary('id');
            $t->unique(['slug', 'locale']);
            $t->index(['status', 'locale']);
        });
        Schema::create('cms_blocks', function (Blueprint $t) {
            $t->string('id', 26);
            $t->string('page_id', 26);
            $t->string('type', 64);
            $t->text('content');
            $t->integer('sort')->default(0);
            $t->string('status', 20)->default('draft');
            $t->timestamps();
            $t->primary('id');
            $t->index(['page_id', 'status', 'sort']);
        });
        Schema::create('wishlists', function (Blueprint $t) {
            $t->string('id', 26);
            $t->string('customer_id', 26);
            $t->string('product_id', 26);
            $t->string('variant_id', 26)->nullable();
            $t->datetime('created_at')->nullable();
            $t->primary('id');
            $t->unique(['customer_id', 'product_id']);
            $t->index('customer_id');
        });
        Schema::create('product_reviews', function (Blueprint $t) {
            $t->string('id', 26);
            $t->string('product_id', 26);
            $t->string('variant_id', 26)->nullable();
            $t->string('customer_id', 26);
            $t->string('order_id', 26);
            $t->string('order_line_id', 26);
            $t->integer('rating');
            $t->string('title', 255)->default('');
            $t->text('body');
            $t->string('status', 20)->default('pending');
            $t->string('moderation_note', 255)->default('');
            $t->datetime('created_at')->nullable();
            $t->datetime('updated_at')->nullable();
            $t->primary('id');
            $t->unique(['customer_id', 'order_line_id']);
            $t->index(['product_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::drop('product_reviews');
        Schema::drop('wishlists');
        Schema::drop('cms_blocks');
        Schema::drop('cms_pages');
    }
};
