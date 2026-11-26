<?php
declare(strict_types=1);

use Siro\Core\DB\Blueprint;
use Siro\Core\Schema;

return new class {
    public function up(): void
    {
        Schema::create('tags', function (Blueprint $t): void {
            $t->id(); $t->string('name', 128); $t->string('slug', 128); $t->string('color', 32)->nullable();
            $t->timestamps(); $t->unique('slug');
        });
        Schema::create('posts', function (Blueprint $t): void {
            $t->id(); $t->string('title', 255); $t->string('slug', 255); $t->text('excerpt')->nullable(); $t->text('content')->nullable();
            $t->string('image', 512)->nullable(); $t->string('status', 20)->default('draft'); $t->integer('featured')->default(0);
            $t->string('category_id', 26)->nullable(); $t->text('tags_json')->nullable(); $t->string('author_name', 128)->nullable();
            $t->datetime('published_at')->nullable(); $t->timestamps(); $t->unique('slug'); $t->index(['status', 'published_at']);
        });
        Schema::create('settings', function (Blueprint $t): void {
            $t->string('key', 64); $t->text('value')->nullable(); $t->datetime('updated_at')->nullable();
            $t->primary('key');
        });
        Schema::table('categories', function (Blueprint $t): void {
            $t->text('description')->nullable(); $t->string('color', 32)->nullable(); $t->string('icon', 128)->nullable();
            $t->integer('sort_order')->default(0); $t->integer('is_active')->default(1);
        });
    }

    public function down(): void
    {
        Schema::drop('settings'); Schema::drop('posts'); Schema::drop('tags');
        Schema::table('categories', function (Blueprint $t): void { $t->dropColumn('is_active'); $t->dropColumn('sort_order'); $t->dropColumn('icon'); $t->dropColumn('color'); $t->dropColumn('description'); });
    }
};
