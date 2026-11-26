<?php

declare(strict_types=1);

use Siro\Core\DB\Blueprint;
use Siro\Core\Schema;

return new class {
    public function up(): void
    {
        Schema::table('cms_pages', function (Blueprint $t): void {
            $t->datetime('scheduled_at')->nullable();
            $t->integer('version')->default(1);
            $t->string('canonical', 512)->nullable();
            $t->string('seo_title', 255)->nullable();
            $t->string('seo_description', 512)->nullable();
            $t->string('seo_robots', 64)->default('index,follow');
            $t->text('seo_schema_json')->nullable();
            $t->text('media_json')->nullable();
            $t->index(['status', 'scheduled_at']);
        });
        Schema::table('seo_meta', function (Blueprint $t): void { $t->text('schema_json')->nullable(); });
        Schema::table('cms_blocks', function (Blueprint $t): void {
            $t->integer('version')->default(1);
            $t->text('media_json')->nullable();
        });
        Schema::create('cms_revisions', function (Blueprint $t): void {
            $t->string('id', 26);
            $t->string('entity_type', 32);
            $t->string('entity_id', 26);
            $t->integer('version');
            $t->text('snapshot_json');
            $t->string('action', 32);
            $t->string('actor_id', 26)->nullable();
            $t->datetime('created_at')->nullable();
            $t->primary('id');
            $t->unique(['entity_type', 'entity_id', 'version']);
            $t->index(['entity_type', 'entity_id']);
        });
        Schema::create('cms_menus', function (Blueprint $t): void {
            $t->string('id', 26);
            $t->string('key', 64);
            $t->string('locale', 10)->default('en');
            $t->string('status', 20)->default('draft');
            $t->integer('version')->default(1);
            $t->primary('id');
            $t->unique(['key', 'locale']);
        });
        Schema::create('cms_menu_items', function (Blueprint $t): void {
            $t->string('id', 26);
            $t->string('menu_id', 26);
            $t->string('label', 255);
            $t->string('target', 512);
            $t->string('target_type', 16)->default('path');
            $t->string('parent_id', 26)->nullable();
            $t->integer('sort')->default(0);
            $t->string('status', 20)->default('draft');
            $t->primary('id');
            $t->index(['menu_id', 'status', 'sort']);
        });
    }

    public function down(): void
    {
        Schema::drop('cms_menu_items');
        Schema::drop('cms_menus');
        Schema::drop('cms_revisions');
        Schema::table('cms_blocks', function (Blueprint $t): void { $t->dropColumn('media_json'); $t->dropColumn('version'); });
        Schema::table('cms_pages', function (Blueprint $t): void {
            $t->dropIndex('idx_cms_pages_status_scheduled_at');
            $t->dropColumn('media_json'); $t->dropColumn('seo_schema_json'); $t->dropColumn('seo_robots');
            $t->dropColumn('seo_description'); $t->dropColumn('seo_title'); $t->dropColumn('canonical');
            $t->dropColumn('version'); $t->dropColumn('scheduled_at');
        });
        Schema::table('seo_meta', function (Blueprint $t): void { $t->dropColumn('schema_json'); });
    }
};
