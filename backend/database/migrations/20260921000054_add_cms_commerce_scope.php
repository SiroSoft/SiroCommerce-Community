<?php

declare(strict_types=1);

use Siro\Core\DB\Blueprint;
use Siro\Core\Schema;

return new class {
    public function up(): void
    {
        Schema::table('cms_pages', function (Blueprint $t): void {
            $t->string('store_id', 64)->default('default');
            $t->string('channel_id', 64)->default('default');
            $t->string('currency', 3)->default('VND');
            $t->index(['store_id', 'channel_id', 'locale', 'currency', 'status'], 'idx_cms_pages_scope');
        });
        Schema::table('cms_revisions', function (Blueprint $t): void {
            $t->string('store_id', 64)->default('default');
            $t->string('channel_id', 64)->default('default');
            $t->string('locale', 10)->default('en');
            $t->string('currency', 3)->default('VND');
            $t->index(['store_id', 'channel_id', 'locale', 'currency'], 'idx_cms_revisions_scope');
        });
    }

    public function down(): void
    {
        Schema::table('cms_revisions', function (Blueprint $t): void { $t->dropIndex('idx_cms_revisions_scope'); $t->dropColumn('currency'); $t->dropColumn('locale'); $t->dropColumn('channel_id'); $t->dropColumn('store_id'); });
        Schema::table('cms_pages', function (Blueprint $t): void { $t->dropIndex('idx_cms_pages_scope'); $t->dropColumn('currency'); $t->dropColumn('channel_id'); $t->dropColumn('store_id'); });
    }
};
